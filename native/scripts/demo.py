"""Seed only a freshly initialized demo via the real HTTP API; write private credentials."""
import datetime as dt
import json
import os
from pathlib import Path
import secrets
import sys
from zoneinfo import ZoneInfo
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tests'))
from client import Client
initial=Path(sys.argv[1]); output=Path(sys.argv[2]); port=int(sys.argv[3])
if output.exists(): raise SystemExit('Demo credentials already exist; refusing to reseed.')
seed=json.loads(initial.read_text()); manager=Client(port)
# The private loopback call must still declare the actual deployed origin.
manager.origin=sys.argv[4]
def call(c,method,path,data=None):
 r=c.request(method,path,data)
 if r[0]!=200: raise RuntimeError(f'{path}: status {r[0]}, {r[1].get("error","")}')
 return r[1]
call(manager,'POST','/api/login',seed)
password=secrets.token_urlsafe(18)
call(manager,'POST','/api/password',{'old_password':seed['password'],'new_password':password})
call(manager,'PATCH','/api/profile',{'first_name':'מנהל','last_name':'הדגמה','gender':'other'})
credentials={'url':manager.origin,'manager':{'login':seed['login'],'password':password},'worker':None}
now=dt.datetime.now(ZoneInfo('Asia/Jerusalem')).date();start=now-dt.timedelta(days=(now.weekday()+1)%7)
for i,name in enumerate(['דנה','נועם','מאיה','יובל','תמר','איתי']):
 pw=secrets.token_urlsafe(18);login=f'demo-{i+1}'
 call(manager,'POST','/api/admin/users',{'login':login,'first_name':name,'last_name':'הדגמה','gender':'other','role':'member','password':pw})
 c=Client(port);c.origin=manager.origin
 call(c,'POST','/api/login',{'login':login,'password':pw})
 for n in range(14):
  day=start+dt.timedelta(days=n)
  if day>=now and day.weekday() in (6,0,1,2,3) and (i+n)%3!=0:
   call(c,'POST','/api/shifts',{'date':day.isoformat(),'period':['09_13','10_14','11_15','12_16','full'][(i+n)%5]})
 if i==0: credentials['worker']={'login':login,'password':pw}
 call(c,'POST','/api/logout',{})
call(manager,'POST','/api/admin/events',{'date':(start+dt.timedelta(days=9)).isoformat(),'kind':'event','title':'מפגש משותף — הדגמה','description':'רשומת הדגמה בלבד'})
call(manager,'POST','/api/logout',{})
fd=os.open(output,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
with os.fdopen(fd,'w') as f: json.dump(credentials,f,ensure_ascii=False,indent=2)
print('Demo populated; credentials saved to private file.')
