# Test backups for the e2e tests of the backups list (0.8.451): python3 fakebackups.py <backup dir> <source backup zip> <count>
# Copies the source backup <count> times with older dates (1, 2, … days back), versions 0.8.1xx and reason "update to …"; the 5th copy
# is a safety backup ("before rollback"). Prints the created file names, one per line. Names start with nunext-e2e- (easy to remove).
import json, sys, zipfile, os, datetime, secrets

dest, src, count = sys.argv[1], sys.argv[2], int(sys.argv[3])
with zipfile.ZipFile(src) as z:
    meta = json.loads(z.read('backup.json'))
now = datetime.datetime.now(datetime.timezone.utc)
for i in range(1, count + 1):
    when = now - datetime.timedelta(days=i)
    ver = '0.8.%d' % (199 - i)
    m = dict(meta, version=ver, created=when.strftime('%Y-%m-%dT%H:%M:%S+00:00'),
             reason='before rollback' if i == 5 else 'update to 0.8.%d' % (200 - i), by='e2e', sql=None)
    m.pop('sql', None)
    name = 'nunext-e2e-%s-%s-%s.zip' % (ver, when.strftime('%Y%m%d-%H%M%S'), secrets.token_hex(4))
    with zipfile.ZipFile(os.path.join(dest, name), 'w', zipfile.ZIP_DEFLATED) as out:
        out.writestr('backup.json', json.dumps(m))
        out.writestr('readme.txt', 'e2e test backup ' + str(i) + ' ' + ('x' * (i * 1000)))
    print(name)
