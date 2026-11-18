from client import *
import csv

class ImportTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='shift-import-');self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name);self.db=self.path/'target.db'
    def invoke(self,*args):
        return subprocess.run(['python3',str(ROOT/'scripts/import-data.py'),*map(str,args)],text=True,capture_output=True)
    def test_csv_all_or_nothing_literal_hash_skip_and_server_lock(self):
        seed=subprocess.run([str(BINARY),'--init','--db',str(self.db)],capture_output=True,text=True,check=True)
        source=self.path/'workers.csv';literal='$2y$04$abcdefghijklmnopqrstuuY1cr1HM7UyJZpS4YUJOmn.HmrqKhvsO'
        with source.open('w') as f:
            writer=csv.writer(f);writer.writerow(['username','first_name','last_name','gender','password']);writer.writerow(['csv-worker','חדש','בדיקה','other',literal])
        self.assertEqual(self.invoke('csv',source,self.db,'--dry-run').returncode,0)
        with sqlite3.connect(self.db) as db:self.assertEqual(db.execute('SELECT count(*) FROM users').fetchone()[0],1)
        self.assertEqual(self.invoke('csv',source,self.db).returncode,0)
        self.assertEqual(self.invoke('csv',source,self.db).returncode,0)
        with sqlite3.connect(self.db) as db:
            self.assertEqual(db.execute('SELECT count(*) FROM users').fetchone()[0],2)
            h=db.execute("SELECT password FROM users WHERE login='csv-worker'").fetchone()[0]
            self.assertNotEqual(h,literal);self.assertTrue(h.startswith('$argon2id$'))
        import fcntl
        with open(str(self.db)+'.lock','a+') as lock:
            fcntl.lockf(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.assertEqual(self.invoke('csv',source,self.db).returncode,1)
        source.write_text(source.read_text()+'bad,bad,bad,invalid,short\n')
        self.assertEqual(self.invoke('csv',source,self.db).returncode,1)
        with sqlite3.connect(self.db) as db:self.assertEqual(db.execute('SELECT count(*) FROM users').fetchone()[0],2)
    def test_csv_rejects_ambiguous_headers_before_dry_run_or_write(self):
        subprocess.run([str(BINARY), '--init', '--db', str(self.db)], capture_output=True, check=True)
        headers = ['username', 'first_name', 'last_name', 'gender', 'password']
        values = ['csv-worker', 'חדש', 'בדיקה', 'other', 'First-password-17']
        source = self.path / 'invalid.csv'
        for label, fields, row in [
            ('duplicate', headers + ['password'], values + ['Second-password-17']),
            ('unexpected', headers + ['role'], values + ['manager']),
            ('missing', headers[:-1], values[:-1]),
            ('empty', [], []),
        ]:
            for header_only in (False, True):
                with source.open('w') as f:
                    writer = csv.writer(f)
                    writer.writerow(fields)
                    if not header_only:
                        writer.writerow(row)
                for flags in (('--dry-run',), ()):
                    with self.subTest(case=label, header_only=header_only, flags=flags):
                        result = self.invoke('csv', source, self.db, *flags)
                        self.assertEqual(result.returncode, 1, result.stdout)
                        self.assertNotIn('First-password-17', result.stdout + result.stderr)
                        self.assertNotIn('Second-password-17', result.stdout + result.stderr)
                        with sqlite3.connect(self.db) as db:
                            self.assertEqual(db.execute('SELECT count(*) FROM users').fetchone()[0], 1)

    def test_snapshot_preserves_ids_cancellations_pending_and_refuses_overwrite(self):
        pw=subprocess.check_output([str(BINARY),'--hash-password'],input=b'Import-password-17').decode().strip()
        users=[{'id':i,'username':f'import-{i}','first_name':'חדש','last_name':'בדיקה','gender':'other','birth_date':None,'role':'manager' if i==8 else 'worker','is_active':True,'password':pw} for i in [8,19]]
        doc={'format':'shift-management-laravel-v1','tables':{'users':users,'desks':[{'id':42,'name':'עמדה','is_active':True}],'shifts':[{'id':71,'worker_id':19,'shift_date':'2026-11-17','period':'09_13','cancelled_at':'2026-11-17 12:00:00','cancelled_by':8,'cancellation_reason':'date_blocked','cancellation_note':'סגור','created_at':'2026-11-16 12:00:00'}],'blocked_dates':[{'date':'2026-11-17','reason':'סגור','blocked_by':8}],'desk_reservations':[],'calendar_events':[],'monthly_receipts':[{'worker_id':19,'month':'2026-11-01','has_receipts':True}],'signup_settings':[{'role':'worker','is_enabled':False},{'role':'manager','is_enabled':False}],'pending_signup_requests':[{**users[1],'id':85,'username':'pending-19','created_at':'2026-11-17 12:00:00'}]},'avatars':[]}
        source=self.path/'snapshot.json';source.write_text(json.dumps(doc))
        r=self.invoke('snapshot',source,self.db);self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertEqual(self.invoke('snapshot',source,self.db).returncode,1)
        with sqlite3.connect(self.db) as db:
            self.assertEqual(db.execute('SELECT id FROM users ORDER BY id').fetchall(),[(8,),(19,)])
            self.assertEqual(db.execute('SELECT id,cancellation_note FROM shifts').fetchall(),[(71,'סגור')])
            self.assertEqual(db.execute('SELECT id,login FROM pending_signups').fetchall(),[(85,'pending-19')])
            self.assertEqual(db.execute('SELECT count(*) FROM sessions').fetchone()[0],0)
            self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(),[])
        self.assertEqual(self.db.stat().st_nlink,1)
