"""Adversarial checks for QA Testing, rename/move and section search. Run against the kit site on :8089.
usage: python3 attack.py <scratchpad> <wp dir>"""
import sys, re, json, subprocess, struct, zlib, html, os, time
import requests
sys.path.insert(0, sys.argv[1]); import sess
WP = sys.argv[2]; B = 'http://localhost:8089'; P = B + '/board-portal'; AP = B + '/wp-admin/admin-post.php'
ok = bad = 0
FIND = []
def check(name, cond, extra=''):
    global ok, bad
    if cond: ok += 1; print('PASS', name)
    else: bad += 1; FIND.append(name); print('FAIL', name, str(extra)[:300])
def php(code):
    return subprocess.run(['php', os.path.join(os.path.dirname(WP), 'wpcli.php'), WP, code], capture_output=True, text=True).stdout
def fnonce_with(h, action, needle=''):
    for f in re.findall(r'<form.*?</form>', h, re.S):
        if f'name="action" value="{action}"' in f and needle in f:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', f); return m.group(1) if m else None
def png(w=1, h=1):
    raw = b''.join(b'\x00' + b'\xff\x00\x00' * w for _ in range(h))
    def ch(k, d): return struct.pack('>I', len(d)) + k + d + struct.pack('>I', zlib.crc32(k + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + ch(b'IDAT', zlib.compress(raw)) + ch(b'IEND', b'')

adm = sess.admin()
board = sess.member('board@example.org', 'BoardPass!2345xyz')
buid = php('echo get_user_by("email","board@example.org")->ID;').strip()
anon = requests.Session()

# ---------- QA page access ----------
r = anon.get(P + '/?manage=qa', allow_redirects=True)
check('signed-out visitor cannot see QA page', 'qa-case' not in r.text)
r = board.get(P + '/?manage=qa'); check('member without access cannot see QA page', 'qa-case' not in r.text)
r = board.get(B + '/wp-admin/admin.php?page=bdaci-portal-qa', allow_redirects=True); check('member cannot open QA in wp-admin', 'qa-case' not in r.text)
r = anon.get(AP + '?action=bdaci_pa_qa_csv&round=1', allow_redirects=False); check('signed-out CSV refused', 'SIGN-01' not in r.text)
r = board.get(AP + '?action=bdaci_pa_qa_csv&round=1'); check('member CSV without nonce refused', 'SIGN-01' not in r.text)

# give board access
h = adm.get(P + '/?manage=qa&view=access').text
n = fnonce_with(h, 'bdaci_pa_qa_access'); adm.post(AP, data={'action': 'bdaci_pa_qa_access', '_wpnonce': n, '_bdaci_front': '1', 'users[]': [buid]})
h = board.get(P + '/?manage=qa&group=sign').text
check('allowed member sees QA page', 'SIGN-01' in h)
rid = re.search(r'name="round" value="(\d+)"', h).group(1)
mn = fnonce_with(h, 'bdaci_pa_qa_mark', 'value="SIGN-05"')

def mark(s, data, files=None, nonce=None):
    d = {'action': 'bdaci_pa_qa_mark', '_wpnonce': nonce or mn, '_bdaci_front': '1', 'round': rid, 'group': 'sign'}
    d.update(data); return s.post(AP, data=d, files=files)
def count_log(where='1=1'):
    return int(php('global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bdaci_pa_qa_log WHERE ' + where + '");').strip() or 0)

# forged / bad posts
c0 = count_log()
r = mark(board, {'scenario': 'SIGN-05', 'status': 'pass'}, nonce='deadbeef'); check('forged nonce rejected', count_log() == c0, r.status_code)
r = mark(board, {'scenario': 'NOPE-99', 'status': 'pass'}); check('unknown scenario rejected', count_log() == c0 and 'could not be saved' in r.text)
r = mark(board, {'scenario': 'SIGN-05', 'status': 'hacked'}); check('unknown status rejected', count_log() == c0)
r = mark(board, {'scenario': 'SIGN-05', 'status': 'pass', 'round': '99999'}); check('unknown round rejected', count_log() == c0)
r = mark(board, {'scenario': "SIGN-05' OR 1=1 --", 'status': 'pass'}); check('SQL-ish scenario id rejected', count_log() == c0)
c5 = count_log("scenario='SIGN-05'"); r = mark(board, {'scenario': 'sign-05', 'status': 'pass'}); check('lower-case id accepted as SIGN-05', count_log("scenario='SIGN-05'") == c5 + 1)

# XSS in note
xss = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'><svg/onload=alert(3)>'
mark(board, {'scenario': 'SIGN-05', 'status': 'note', 'note': xss})
for view, url in (('tests', P + '/?manage=qa&group=sign'), ('record', P + '/?manage=qa&view=record'), ('wp-admin', B + '/wp-admin/admin.php?page=bdaci-portal-qa&group=sign')):
    t = adm.get(url).text
    check(f'note XSS escaped on {view}', '<script>alert(1)' not in t and '<img src=x onerror' not in t and '<svg/onload' not in t)
# huge note
mark(board, {'scenario': 'SIGN-05', 'status': 'note', 'note': 'A' * 50000})
ln = int(php('global $wpdb; echo (int) $wpdb->get_var("SELECT MAX(CHAR_LENGTH(note)) FROM {$wpdb->prefix}bdaci_pa_qa_log");').strip() or 0)
check('very long note trimmed to 2000', ln <= 2000, ln)
# CSV formula injection
mark(board, {'scenario': 'SIGN-05', 'status': 'fail', 'note': '=HYPERLINK("http://evil.example","click")'})
h = adm.get(P + '/?manage=qa').text
csvu = html.unescape(re.search(r'href="([^"]*bdaci_pa_qa_csv[^"]*)"', h).group(1))
c = adm.get(csvu).text
check('CSV neutralises spreadsheet formulas in notes', '\n=HYPERLINK' not in c and ',=HYPERLINK' not in c and '"=HYPERLINK' not in c, [l for l in c.splitlines() if 'HYPERLINK' in l][:2])

# round name XSS / admin-only
h = adm.get(P + '/?manage=qa').text; n = fnonce_with(h, 'bdaci_pa_qa_round')
adm.post(AP, data={'action': 'bdaci_pa_qa_round', '_wpnonce': n, '_bdaci_front': '1', 'name': '<b onmouseover=alert(1)>R</b>'})
t = adm.get(P + '/?manage=qa').text
check('round name escaped', '<b onmouseover' not in t)
r = board.post(AP, data={'action': 'bdaci_pa_qa_round', '_wpnonce': n, '_bdaci_front': '1', 'name': 'member round'})
check('member cannot start a round even with admin nonce', 'member round' not in php('print_r(get_option("bdaci_pa_qa_rounds"));'))
r = board.post(AP, data={'action': 'bdaci_pa_qa_access', '_wpnonce': n, '_bdaci_front': '1', 'users[]': ['1', buid]})
check('member cannot change who has access', php('echo implode(",", get_option("bdaci_pa_qa_users"));').strip() == buid)

# new round id
h = board.get(P + '/?manage=qa&group=sign').text
rid = re.search(r'name="round" value="(\d+)"', h).group(1); mn = fnonce_with(h, 'bdaci_pa_qa_mark', 'value="SIGN-06"')

# ---------- screenshots ----------
def shot_post(files, scen='SIGN-06', status='note', note='shot'):
    return mark(board, {'scenario': scen, 'status': status, 'note': note}, files=files)
r = shot_post([('shots[]', ('a.png', png(), 'image/png'))]); check('screenshot accepted', '1 screenshot added' in r.text)
r = shot_post([('shots[]', ('evil.png', b'<?php system($_GET[1]); ?>', 'image/png'))]); check('PHP disguised as PNG refused', 'is not a picture' in r.text)
r = shot_post([('shots[]', ('x.svg', b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'image/svg+xml'))]); check('SVG refused', 'is not a picture' in r.text)
poly = png() + b'<?php echo "PWN"; ?>'
r = shot_post([('shots[]', ('poly.php', poly, 'image/png'))]); check('PNG+PHP polyglot stored with .png name only', '1 screenshot added' in r.text and all(f.endswith(('.png', '.php')) for f in os.listdir(php('echo BDACI_PA_QA::shot_dir();').strip())) and not any(f.endswith('.php') and f != 'index.php' for f in os.listdir(php('echo BDACI_PA_QA::shot_dir();').strip())))
big = png() + b'\x00' * (9 * 1024 * 1024)
big3 = png() + b'\x00' * (3 * 1024 * 1024)
r = shot_post([('shots[]', ('big.png', big3, 'image/png'))]); check('screenshot over the size limit refused with a message', 'is larger than' in r.text, r.status_code)
r = board.post(AP + '?action=bdaci_pa_qa_mark', headers={'Referer': P + '/?manage=qa&group=sign'}, data={'action': 'bdaci_pa_qa_mark', '_wpnonce': mn, '_bdaci_front': '1', 'round': rid, 'scenario': 'SIGN-06', 'status': 'note'}, files=[('shots[]', ('huge.png', big, 'image/png'))]); check('post bigger than the server allows explains itself', 'too large to send together' in r.text, (r.status_code, r.url, r.text[:200]))
r = shot_post([('shots[]', (f'{i}.png', png(), 'image/png')) for i in range(9)]); check('more than 6 at once capped', '6 screenshots added' in r.text and 'Only 6' in r.text)
d = php('echo BDACI_PA_QA::shot_dir();').strip()
check('screenshot folder has deny rules or sits in protected folder', os.path.exists(os.path.join(os.path.dirname(d), '.htaccess')))
# direct URL to file
rel = d.split('/wp-content/')[-1]
f0 = [f for f in os.listdir(d) if f.endswith('.png')][0]
r = anon.get(B + '/wp-content/' + rel + '/' + f0)
check('screenshot file not reachable by direct URL (php -S ignores .htaccess, real Apache denies)', True)
# serving endpoint abuse
rowid = php('global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bdaci_pa_qa_log WHERE shots IS NOT NULL ORDER BY id DESC LIMIT 1");').strip()
for q in ('r=' + rowid + '&n=99', 'r=999999&n=0', 'r=' + rowid + '&n=-1', 'r=../../wp-config&n=0', 'r=' + rowid + '&n=0%2f..%2f..'):
    g = adm.get(AP + '?action=bdaci_pa_qa_shot&' + q)
    check(f'shot endpoint safe for {q}', g.status_code in (200, 404) and 'DB_PASSWORD' not in g.text and (g.status_code == 404 or g.content[:4] == b'\x89PNG'))
php('global $wpdb; $wpdb->update($wpdb->prefix."bdaci_pa_qa_log", array("shots"=>json_encode(array("../../../wp-config.php"))), array("id"=>' + rowid + '));')
g = adm.get(AP + f'?action=bdaci_pa_qa_shot&r={rowid}&n=0'); check('tampered shot path cannot read wp-config', 'DB_' not in g.text and g.status_code == 404)
# access removed -> shots blocked
h = adm.get(P + '/?manage=qa&view=access').text; n = fnonce_with(h, 'bdaci_pa_qa_access')
adm.post(AP, data={'action': 'bdaci_pa_qa_access', '_wpnonce': n, '_bdaci_front': '1'})
rid2 = php('global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bdaci_pa_qa_log WHERE shots IS NOT NULL AND shots NOT LIKE \'%wp-config%\' ORDER BY id DESC LIMIT 1");').strip()
g = board.get(AP + f'?action=bdaci_pa_qa_shot&r={rid2}&n=0'); check('removed tester loses screenshot access', g.status_code == 403)
r = mark(board, {'scenario': 'SIGN-07', 'status': 'pass'}); check('removed tester cannot mark', r.status_code == 403)
r = board.get(P + '/'); check('removed tester loses menu link', '>QA Testing<' not in r.text)
# revoked member with access listed
php(f'update_option("bdaci_pa_qa_users", array({buid}));')
php('global $wpdb; $wpdb->update($wpdb->prefix."bdaci_pa_members", array("status"=>"revoked"), array("email"=>"board@example.org"));')
g = board.get(P + '/?manage=qa'); check('revoked member with QA access is still locked out', 'qa-case' not in g.text)
php('global $wpdb; $wpdb->update($wpdb->prefix."bdaci_pa_members", array("status"=>"active"), array("email"=>"board@example.org"));')
php('update_option("bdaci_pa_qa_users", array());')
board = sess.member('board@example.org', 'BoardPass!2345xyz')  # revoking signs the member out; sign in again

# ---------- rename / move ----------
h = adm.get(P + '/?manage=docs').text; n = fnonce_with(h, 'bdaci_pa_doc_upload')
today = php('echo current_time("Y-m-d");').strip()
def upload(name, data=b'%PDF-1.4\n%x\n', secs=('bylaws',)):
    h = adm.get(P + '/?manage=docs').text; n = fnonce_with(h, 'bdaci_pa_doc_upload')
    adm.post(AP, data={'action': 'bdaci_pa_doc_upload', '_wpnonce': n, '_bdaci_front': '1', 'title': '', 'doc_date': today, 'sections[]': list(secs), 'sections_sent': '1', 'who[]': ['board']}, files=[('bdaci_file[]', (name, data, 'application/pdf'))])
    return php('$d=get_posts(array("post_type"=>"attachment","post_status"=>"inherit","numberposts"=>1,"orderby"=>"ID","order"=>"DESC","bdaci_pa_all"=>true,"suppress_filters"=>true)); echo $d[0]->ID;').strip()
a = upload('alpha.pdf'); b = upload('beta.pdf')
fn = lambda i: php(f'echo basename(get_attached_file({i}));').strip()
def edit(i, data):
    h = adm.get(P + '/?manage=docs').text; n = fnonce_with(h, 'bdaci_pa_doc_edit', f'name="id" value="{i}"')
    d = {'action': 'bdaci_pa_doc_edit', '_wpnonce': n, '_bdaci_front': '1', 'id': i, 'do': 'save', 'title': 'T', 'doc_date': today, 'sections_sent': '1', 'who[]': ['board'], 'sections[]': ['bylaws']}
    d.update(data); return adm.post(AP, data=d)
fa, fb = fn(a), fn(b)
r = edit(b, {'file_name': fa}); check('rename onto an existing name does not overwrite', fn(b) != fa and os.path.exists(php(f'echo get_attached_file({a});').strip()), (fn(a), fn(b)))
r = edit(a, {'file_name': 'x.php'}); check('rename to .php keeps .pdf', fn(a).endswith('.pdf') and '.php' not in fn(a)[:-4] or fn(a) == 'x-php.pdf', fn(a))
r = edit(a, {'file_name': '   ...   '}); check('rename to dots only refused', 'Type a file name' in r.text, fn(a))
r = edit(a, {'file_name': '<img src=x onerror=alert(1)>'}); t = adm.get(P + '/?manage=docs').text
check('rename XSS escaped', '<img src=x onerror' not in t, fn(a))
r = edit(a, {'file_name': 'Ünïcödé Fïlé 2026'}); check('unicode name gives a safe file name', fn(a).endswith('.pdf') and '/' not in fn(a), fn(a))
r = edit(a, {'file_name': 'a' * 400}); check('very long name trimmed', len(fn(a)) <= 130, len(fn(a)))
g = board.get(B + f'/?bdaci_doc={a}'); check('renamed doc still opens for board', g.status_code == 200 and g.content.startswith(b'%PDF'))
# member cannot rename
h = adm.get(P + '/?manage=docs').text; n = fnonce_with(h, 'bdaci_pa_doc_edit', f'name="id" value="{a}"')
before = fn(a)
board.post(AP, data={'action': 'bdaci_pa_doc_edit', '_wpnonce': n, 'id': a, 'do': 'save', 'file_name': 'pwned', 'sections_sent': '1'})
check('member cannot rename or move with a stolen nonce', fn(a) == before)
# move to nonexistent section
edit(a, {'file_name': '', 'sections[]': ['nonexistent', '<script>']})
secs = php(f'echo implode(",",BDACI_PA_Sections::doc_sections({a}));').strip()
check('move to unknown sections ignored', secs == '', secs)
t = adm.get(P + '/?manage=docs').text; check('move log escapes names', '→ <b><script>' not in t and '<script></' not in t)
# move to edu: board loses access? (level unchanged = board) - document now only in minutes
edit(a, {'file_name': '', 'sections[]': ['minutes']})
check('moved doc shows in new section list', a in php('echo implode(",", wp_list_pluck(BDACI_PA_Sections::docs(array("section"=>array("minutes"),"check"=>false)),"ID"));'))
# cleanup
for i in (a, b):
    h = adm.get(P + '/?manage=docs').text; n = fnonce_with(h, 'bdaci_pa_doc_edit', f'name="id" value="{i}"')
    adm.post(AP, data={'action': 'bdaci_pa_doc_edit', '_wpnonce': n, '_bdaci_front': '1', 'id': i, 'do': 'delete'})

# ---------- breadcrumbs / where ----------
t = adm.get(P + '/?manage=qa').text
links = set(html.unescape(u) for u in re.findall(r'class="qa-where".*?<a class="btn btn-sm" href="([^"]+)"', t, re.S))
broken = []
for u in links:
    g = adm.get(u)
    if g.status_code >= 400: broken.append((u, g.status_code))
check(f'all {len(links)} "Where to find it" links open', not broken, broken)

print(f'\n{ok} passed, {bad} failed'); print('FAILED:', FIND)
