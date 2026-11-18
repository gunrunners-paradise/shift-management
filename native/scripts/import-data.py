"""Offline migration and worker CSV import. Server must be stopped for writes.

A snapshot is installed to a NEW database only; existing files are never replaced.
Legacy bcrypt verification is supported by the Linux C runtime (libxcrypt).
"""
import argparse, base64, csv, datetime, fcntl, hashlib, io, json, os, re, sqlite3, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
ROLE={'worker':'member','manager':'admin'}

def person(r, password=True):
    login=r['username'].strip().lower()
    if not re.fullmatch(r'[a-z0-9][a-z0-9._-]{2,59}',login): raise ValueError('Invalid username')
    for key in ('first_name','last_name'):
        if not isinstance(r[key],str) or not 1<=len(r[key].strip())<=60 or '\0' in r[key]: raise ValueError('Invalid name')
    if r['gender'] not in ('male','female','other'): raise ValueError('Invalid gender')
    birth=r.get('birth_date') or None
    if birth and (datetime.date.fromisoformat(birth).isoformat()!=birth or birth>datetime.date.today().isoformat()): raise ValueError('Invalid birthday')
    if password and (len(r['password'])<12 or len(r['password'].encode())>128 or '\0' in r['password']): raise ValueError('Invalid password')
    return [login,r['first_name']+' '+r['last_name'],r['first_name'],r['last_name'],r['gender'],birth]

def connect(path):
    db=sqlite3.connect(path);db.execute('PRAGMA foreign_keys=ON');db.execute('PRAGMA trusted_schema=OFF');db.execute('PRAGMA synchronous=FULL')
    if db.execute('PRAGMA user_version').fetchone()[0] not in (100,101): raise ValueError('Wrong database version')
    return db

def insert(db,table,columns,values):
    db.execute(f'INSERT INTO {table}({",".join(columns)}) VALUES({",".join("?" for _ in columns)})',values)

def migrate(source,target):
    if target.exists(): raise ValueError('Destination must not exist')
    raw=source.read_bytes();doc=json.loads(raw)
    if doc['format']!='shift-management-laravel-v1': raise ValueError('Unknown snapshot format')
    tables=doc['tables']
    for name in ('users','shifts','blocked_dates','desks','desk_reservations','calendar_events','monthly_receipts','signup_settings','pending_signup_requests'):
        if not isinstance(tables[name],list): raise ValueError('Incomplete snapshot')
    # Keep failed attempts in a private work directory for inspection.
    work=Path(tempfile.mkdtemp(prefix='.migration-',dir=target.parent));tmp=work/'candidate.db'
    subprocess.run([str(ROOT/'build/mishmeret'),'--init','--db',str(tmp)],stdout=subprocess.DEVNULL,check=True)
    db=connect(tmp)
    with db:
        db.execute('DELETE FROM users');db.execute('DELETE FROM desks');db.execute('DELETE FROM signup_settings')
        for r in tables['users']:
            values=person(r,False);h=r['password']
            if not (h.startswith('$argon2id$') or re.fullmatch(r'\$2[aby]\$(0[4-9]|1[0-6])\$[./A-Za-z0-9]{53}',h)): raise ValueError('Unsupported password hash')
            insert(db,'users',['id','login','name','first_name','last_name','gender','birth_date','role','active','password'],[r['id'],*values,ROLE[r['role']],int(r['is_active']),h])
        if not db.execute("SELECT 1 FROM users WHERE role='admin' AND active=1").fetchone(): raise ValueError('An active manager is required')
        for r in tables['desks']: insert(db,'desks',['id','name','active'],[r['id'],r['name'],int(r['is_active'])])
        for table,columns in [('shifts',['id','worker_id','shift_date','period','cancelled_at','cancelled_by','cancellation_reason','cancellation_note','created_at']),('blocked_dates',['date','reason','blocked_by']),('desk_reservations',['id','worker_id','desk_id','reservation_date','start_time','end_time','cancelled_at','cancelled_by','cancellation_reason','cancellation_note','created_at'])]:
            for r in tables[table]:
                row=dict(r)
                for key in ('start_time','end_time'):
                    if key in row:
                        if len(row[key])==8 and row[key][-3:]!=':00': raise ValueError('Nonzero seconds')
                        row[key]=row[key][:5]
                insert(db,table,columns,[row.get(k) for k in columns])
        for r in tables['calendar_events']: insert(db,'events',['id','date','title','kind','description'],[r['id'],r['date'],r['title'],r['kind'],r['description'] or ''])
        for r in tables['monthly_receipts']: insert(db,'receipts',['worker_id','month','has_receipts'],[r['worker_id'],r['month'][:7],int(r['has_receipts'])])
        for r in tables['signup_settings']: insert(db,'signup_settings',['role','enabled'],[ROLE[r['role']],int(r['is_enabled'])])
        for r in tables['pending_signup_requests']:
            values=person(r,False)
            insert(db,'pending_signups',['id','login','first_name','last_name','gender','birth_date','role','password','created_at','review_error'],[r['id'],values[0],*values[2:],ROLE[r['role']],r['password'],r['created_at'],r.get('review_error')])
        for r in doc.get('avatars',[]):
            data=base64.b64decode(r['base64'],validate=True)
            if len(data)>2097152 or r['type'] not in ('image/png','image/jpeg','image/webp'): raise ValueError('Invalid avatar')
            insert(db,'avatars',['user_id','type','data'],[r['user_id'],r['type'],data])
        if db.execute('PRAGMA foreign_key_check').fetchone(): raise ValueError('Foreign key mismatch')
        if db.execute('PRAGMA integrity_check').fetchone()[0]!='ok': raise ValueError('Integrity failure')
        overlap=db.execute("SELECT 1 FROM desk_reservations a JOIN desk_reservations b ON a.id<b.id AND a.desk_id=b.desk_id AND a.reservation_date=b.reservation_date AND a.start_time<b.end_time AND b.start_time<a.end_time WHERE a.cancelled_at IS NULL AND b.cancelled_at IS NULL LIMIT 1").fetchone()
        if overlap: raise ValueError('Overlapping desk reservations in source')
    db.execute('PRAGMA wal_checkpoint(TRUNCATE)');db.close()
    # hard-link publishes atomically and refuses an existing target, even in a race.
    os.link(tmp,target)
    # Remove only our extra hard link: runtime rejects multi-link databases.
    tmp.unlink()
    manifest={'source_sha256':hashlib.sha256(raw).hexdigest(),'source':str(source.resolve()),'tables':{k:len(v) for k,v in tables.items()},'sessions_imported':0}
    with open(str(target)+'.migration.json','x') as f: json.dump(manifest,f,indent=2)
    print('Migration complete. IDs, hashes, cancellations and pending requests retained; sign in again.')

def import_csv(source,target,dry):
    if not target.is_file(): raise ValueError('Database not found')
    content=source.read_text(encoding='utf-8-sig')
    if '\0' in content: raise ValueError('Invalid CSV')
    reader = csv.DictReader(io.StringIO(content), strict=True)
    required = {'username', 'first_name', 'last_name', 'gender', 'password'}
    allowed = required | {'birth_date'}
    headers = reader.fieldnames or []
    # DictReader otherwise silently keeps only the last duplicate column.
    # Check even header-only files, before processing credentials or writing.
    if len(headers) != len(set(headers)) or not required <= set(headers) or set(headers) - allowed:
        raise ValueError('Invalid CSV headers')
    rows=list(reader);seen=set()
    for r in rows:
        if None in r or any(v is None for v in r.values()): raise ValueError('CSV column mismatch')
        values=person(r)
        if values[0] in seen: raise ValueError('Duplicate username in CSV')
        seen.add(values[0]);r['_values']=values
    if dry:
        db=sqlite3.connect(target.resolve().as_uri()+'?mode=ro',uri=True)
        existing={r[0] for r in db.execute('SELECT login FROM users')};db.close()
        print(f'Validated: {len(rows)}; new: {len(seen-existing)}; existing skipped: {len(seen&existing)}. No writes.')
        return
    with open(str(target.resolve())+'.lock','a+') as lock:
        fcntl.lockf(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        db=connect(target);created=skipped=0
        with db:
            db.execute('BEGIN IMMEDIATE')
            for r in rows:
                if db.execute('SELECT 1 FROM users WHERE login=?',(r['_values'][0],)).fetchone(): skipped+=1;continue
                hash=subprocess.check_output([str(ROOT/'build/mishmeret'),'--hash-password'],input=r['password'].encode()).decode().strip()
                insert(db,'users',['login','name','first_name','last_name','gender','birth_date','role','password'],[*r['_values'],'member',hash]);created+=1
        db.close();print(f'Workers created: {created}; existing usernames skipped: {skipped}.')

def main():
    os.umask(0o077)
    p=argparse.ArgumentParser();p.add_argument('mode',choices=['snapshot','csv']);p.add_argument('source',type=Path);p.add_argument('database',type=Path);p.add_argument('--dry-run',action='store_true');a=p.parse_args()
    try:
        if a.mode=='snapshot':
            if a.dry_run: raise ValueError('Snapshot mode creates a separate candidate; use a new destination')
            migrate(a.source,a.database)
        else: import_csv(a.source,a.database,a.dry_run)
    except Exception:
        # Never render values, SQL bindings, hash strings or passwords.
        print('Import failed. Source and existing accounts retained. Check file format, destination and server lock.');raise SystemExit(1)
if __name__=='__main__':main()
