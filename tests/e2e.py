import requests, re, json, sys, os, subprocess, urllib.parse as up
B='http://localhost:8089'; S=os.environ['S']; WP=S+'/wp'
ok=0; bad=0
def check(name, cond, extra=''):
    global ok,bad
    if cond: ok+=1; print('PASS', name)
    else: bad+=1; print('FAIL', name, extra)
def php(code):
    return subprocess.run(['php',S+'/wpcli.php',WP,code],capture_output=True,text=True,cwd=WP).stdout
def last_code(to):
    lines=[json.loads(l) for l in open(WP+'/wp-content/mail.log')]
    for l in reversed(lines):
        if l['to']==to and 'verification code' in l['subject']:
            return re.search(r'code is: (\d{6})', l['message']).group(1)
def fnonce(html, action):
    for f in re.findall(r'<form.*?</form>', html, re.S):
        if f'name="action" value="{action}"' in f:
            return re.search(r'name="_wpnonce" value="([^"]+)"', f).group(1)
DOCRE=r'bdaci_document id=(?:&quot;|")(\d+)'
def nonce(html, step):
    forms = re.findall(r'<form.*?</form>', html, re.S)
    for f in forms:
        if f'value="{step}"' in f:
            return re.search(r'name="_bdaci_nonce" value="([^"]+)"', f).group(1)
L=B+'/portal-login/'
def login_pw(s, email, pw, redirect=None):
    r=s.get(L, params={'redirect_to':redirect} if redirect else None)
    data={'log':email,'pwd':pw,'bdaci_step':'password','_bdaci_nonce':nonce(r.text,'password')}
    if redirect: data['redirect_to']=redirect
    return s.post(L, data=data, allow_redirects=False)

php('foreach(["new@example.org","y@example.org","stranger@gmail.com"] as $e){$r=BDACI_PA_Members::get_by_email($e); if($r) BDACI_PA_Members::delete($r->id);} $u=get_user_by("email","gina@gmail.com"); if($u){require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user($u->ID);} $r=BDACI_PA_Members::get_by_email("gina@gmail.com"); BDACI_PA_Members::update($r->id,["user_id"=>0]); global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%bdaci_pa_rl%\'");')
# --- anonymous
a=requests.Session()
for path in ['/board-portal/','/board-portal/education-committee/','/board-portal/education-committee/edu-minutes/','/board-portal/board-only/']:
    r=a.get(B+path, allow_redirects=False)
    check('anon redirected '+path, r.status_code==302 and '/portal-login/' in r.headers.get('Location',''), r.status_code)
    check('anon no-cache '+path, 'no-cache' in r.headers.get('Cache-Control','') or 'no-store' in r.headers.get('Cache-Control',''))
r=a.get(B+'/about-us/'); check('public page visible', 'PUBLIC-ABOUT' in r.text and 'HIDDEN-SNIPPET' not in r.text)
r=a.get(B+'/?s=SECRET'); check('search hides protected', 'SECRET' not in re.sub(r'<input[^>]*>','',r.text).replace('?s=SECRET','').replace('SECRET</','') or 'Board Only' not in r.text, )
check('search no titles', 'Board Only' not in r.text and 'Education Committee' not in r.text)
r=a.get(B+'/?rest_route=/wp/v2/pages'); titles=[p['title']['rendered'] for p in r.json()]
check('REST list hides protected', set(titles)=={'About Us','Sample Page'} or 'Board Portal' not in titles, titles)
r=a.get(B+'/?rest_route=/wp/v2/pages/7'); check('REST single 404', r.status_code==404, r.status_code)
r=a.get(B+'/?rest_route=/wp/v2/pages&search=SECRET'); check('REST search hides', r.json()==[], r.text[:100])
r=a.get(B+'/wp-sitemap-posts-page-1.xml'); check('sitemap hides', 'board-portal' not in r.text and r.status_code==200, r.status_code)
r=a.get(B+'/?feed=rss2&post_type=page'); check('feed hides', 'SECRET' not in r.text)
r=a.get(B+'/?rest_route=/oembed/1.0/embed&url='+up.quote(B+'/board-portal/board-only/')); check('oembed blocked', r.status_code==404, r.status_code)
r=a.get(L); check('login page renders', 'Sign in with Microsoft 365' in r.text and 'Sign in with Google' in r.text and 'Forgot your password' in r.text)
check('login page frame deny', r.headers.get('X-Frame-Options')=='DENY')

# --- wrong password, outsider
s=requests.Session()
r=login_pw(s,'board@example.org','nope'); check('bad password rejected', r.status_code==200 and 'incorrect' in r.text)
r=login_pw(s,'out@example.org','OutPass!2345xyz'); check('non-allowlisted user rejected', 'not authorized' in r.text)
# bad nonce
r=s.post(L,data={'log':'board@example.org','pwd':'BoardPass!2345xyz','bdaci_step':'password','_bdaci_nonce':'bad'}); check('bad nonce rejected','session expired' in r.text)

# --- board member full flow w/ MFA
s=requests.Session()
target=B+'/board-portal/board-only/'
r=login_pw(s,'board@example.org','BoardPass!2345xyz',redirect=target)
check('board pw -> code step', 'Verification code' in r.text and 'bdaci_pa_mfa' in s.cookies.get_dict())
check('not logged in before code', not any(k.startswith('wordpress_logged_in') for k in s.cookies.get_dict()))
code=last_code('board@example.org')
wrong='000000' if code!='000000' else '111111'
r=s.post(L,data={'code':wrong,'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')}); check('wrong code rejected','not correct' in r.text)
r=s.post(L,data={'code':code,'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')},allow_redirects=False)
check('right code -> redirect to target', r.status_code==302 and r.headers['Location']==target, (r.status_code, r.headers.get('Location')))
r=s.get(target); check('board sees board page','SECRET-BOARD' in r.text)
r=s.get(B+'/board-portal/'); check('board sees home','SECRET-PORTAL-HOME' in r.text and 'Sign out' in r.text)
r=s.get(B+'/board-portal/education-committee/',allow_redirects=False); check('board blocked from committee page', r.status_code==403, r.status_code)
r=s.get(B+'/board-portal/education-committee/edu-minutes/',allow_redirects=False); check('board blocked from committee child', r.status_code==403)
r=s.get(B+'/about-us/'); check('shortcode shows for member','HIDDEN-SNIPPET' in r.text)
r=s.get(B+'/wp-admin/',allow_redirects=False); check('board kept out of wp-admin', r.status_code==302 and 'board-portal' in r.headers['Location'], r.headers.get('Location'))
r=s.get(L,allow_redirects=False); check('login page redirects signed-in', r.status_code==302)
# open redirect attempt
s2=requests.Session()
r=login_pw(s2,'comm@example.org','CommPass!2345xyz',redirect='https://evil.example/')
code=last_code('comm@example.org')
r=s2.post(L,data={'code':code,'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')},allow_redirects=False)
check('open redirect blocked', r.status_code==302 and r.headers['Location'].startswith(B), r.headers.get('Location'))
r=s2.get(B+'/board-portal/education-committee/edu-minutes/'); check('committee sees committee child','SECRET-EDU-CHILD' in r.text)
r=s2.get(B+'/board-portal/board-only/',allow_redirects=False); check('committee blocked from board page', r.status_code==403)
r=s2.get(B+'/?rest_route=/wp/v2/pages/7'); check('committee REST board page 404', r.status_code==404)

# board_sees_committee toggle
php('$s=BDACI_PA_Settings::all(); $s["board_sees_committee"]=1; BDACI_PA_Settings::save($s);')
r=s.get(B+'/board-portal/education-committee/'); check('toggle: board sees committee','SECRET-EDU' in r.text)
php('$s=BDACI_PA_Settings::all(); $s["board_sees_committee"]=0; BDACI_PA_Settings::save($s);')

# --- revoke kills session
php('$r=BDACI_PA_Members::get_by_email("board@example.org"); BDACI_PA_Members::update($r->id,["status"=>"revoked"]);')
r=s.get(B+'/board-portal/',allow_redirects=False); check('revoked board session ended', r.status_code==302 and 'portal-login' in r.headers['Location'], (r.status_code,r.headers.get('Location')))
s3=requests.Session(); r=login_pw(s3,'board@example.org','BoardPass!2345xyz'); check('revoked cannot log in','not authorized' in r.text)
php('$r=BDACI_PA_Members::get_by_email("board@example.org"); BDACI_PA_Members::update($r->id,["status"=>"active"]);')

# --- wp-login.php blocked for portal users
s4=requests.Session(); s4.get(B+'/wp-login.php')
r=s4.post(B+'/wp-login.php',data={'log':'board@example.org','pwd':'BoardPass!2345xyz','testcookie':'1'},allow_redirects=False)
check('wp-login blocked for portal user', r.status_code==200 and 'Portal sign-in' in r.text, r.status_code)
r=s4.post(B+'/wp-login.php',data={'log':'admin','pwd':'AdminPass!2345','testcookie':'1'},allow_redirects=False)
check('wp-login still works for admin', r.status_code==302)
r=s4.get(B+'/board-portal/board-only/'); check('admin sees everything','SECRET-BOARD' in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal'); check('admin members page', 'Authorized Users' in r.text and 'gina@gmail.com' in r.text, r.status_code)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-settings'); check('admin settings page', 'portal-login/callback/microsoft/' in r.text and 'ms-secret' not in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-log'); check('log page', 'login_success' in r.text and 'code_failed' in r.text)
# admin add member via form
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal'); n=fnonce(r.text,'bdaci_pa_member_add')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_add','_wpnonce':n,'_wp_http_referer':'/wp-admin/admin.php?page=bdaci-portal','email':'new@example.org','display_name':'New Person','roles[]':['committee'],'invite':'1'})
check('admin add via form', 'new@example.org' in r.text and 'Added.' in r.text, r.status_code)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_add','_wpnonce':'bad','email':'x@example.org','roles[]':['board']})
check('admin add bad nonce refused', r.status_code in (403,) or 'link you followed has expired' in r.text, r.status_code)
r=s.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_add','_wpnonce':n,'email':'y@example.org','roles[]':['admin']})
check('board member cannot add', r.status_code==403 or 'y@example.org' not in php('echo json_encode(BDACI_PA_Members::get_by_email("y@example.org"));'), r.status_code)

# password policy on reset
check('weak pw rule', php('echo BDACI_PA_Login::password_problem("short");')!='' and php('echo BDACI_PA_Login::password_problem("Longenough123!x");')=='')

# --- lockout
s5=requests.Session()
for i in range(5): login_pw(s5,'comm@example.org','wrong'+str(i))
r=login_pw(s5,'comm@example.org','CommPass!2345xyz'); check('lockout after 5 failures','Too many failed' in r.text)
php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%bdaci_pa_rl%\'");')

import time
def claims_google(email, sub='g-sub-1', verified=True, nonce=None, aud='g-client', iss='https://accounts.google.com'):
    return {'iss':iss,'aud':aud,'sub':sub,'email':email,'email_verified':verified,'name':'Gina G','exp':int(time.time())+600,'iat':int(time.time()),'nonce':nonce}
def sso(provider, claim_fn, tamper_state=False, set_nonce=True):
    s=requests.Session()
    r=s.get(L, params={'sso':provider}, allow_redirects=False)
    loc=r.headers['Location']; q=dict(up.parse_qsl(up.urlparse(loc).query))
    c=claim_fn(q['nonce'] if set_nonce else 'wrong-nonce')
    php('update_option("test_fake_claims", '+repr(json.dumps(c))+');')
    state='x'+q['state'] if tamper_state else q['state']
    r=s.get(B+f'/portal-login/callback/{provider}/', params={'state':state,'code':'authcode'}, allow_redirects=False)
    return s, r, q, loc
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n))
check('google authorize URL', loc.startswith('https://accounts.google.com/o/oauth2/v2/auth') and q['code_challenge_method']=='S256' and q['redirect_uri']==B+'/portal-login/callback/google/', loc)
check('google SSO signs in allowlisted', r.status_code==302 and r.headers['Location']==B+'/board-portal/', (r.status_code, r.headers.get('Location')))
r=s.get(B+'/board-portal/education-committee/'); check('google user (board+committee) sees committee page','SECRET-EDU' in r.text)
tok=[json.loads(l) for l in open(WP+'/wp-content/token-req.log')][-1]
check('token request has PKCE verifier + secret', tok[1].get('code_verifier') and tok[1].get('client_secret')=='g-secret' and tok[1]['grant_type']=='authorization_code')
# replay same state
r2=s.get(B+'/portal-login/callback/google/', params={'state':q['state'],'code':'authcode'}, allow_redirects=False)
check('state replay rejected', 'msg=ssofailed' in r2.headers.get('Location',''), r2.headers.get('Location'))
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n), tamper_state=True)
check('tampered state rejected','msg=ssofailed' in r.headers.get('Location',''))
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n), set_nonce=False)
check('wrong nonce rejected','msg=ssofailed' in r.headers.get('Location',''))
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n, aud='other'))
check('wrong audience rejected','msg=ssofailed' in r.headers.get('Location',''))
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n, verified=False))
check('unverified email rejected','msg=unverified' in r.headers.get('Location',''))
s,r,q,loc=sso('google', lambda n: claims_google('gina@gmail.com', nonce=n, sub='attacker-sub'))
check('different subject for bound account rejected','msg=ssomismatch' in r.headers.get('Location',''), r.headers.get('Location'))
s,r,q,loc=sso('google', lambda n: claims_google('stranger@gmail.com', nonce=n, sub='s2'))
check('unknown user -> access request','msg=requested' in r.headers.get('Location',''))
check('request recorded pending', '"pending"' in php('echo json_encode(BDACI_PA_Members::get_by_email("stranger@gmail.com")->status);'))
check('admin notified', any('stranger@gmail.com' in l and 'access request' in l for l in open(WP+'/wp-content/mail.log')))
r=s.get(B+'/board-portal/',allow_redirects=False); check('pending user not signed in', r.status_code==302 and 'portal-login' in r.headers['Location'])
s,r,q,loc=sso('google', lambda n: claims_google('stranger@gmail.com', nonce=n, sub='s2'))
check('pending second try -> pending msg','msg=pending' in r.headers.get('Location',''))
s,r,q,loc=sso('google', lambda n: claims_google('admin@example.org', nonce=n, sub='a1'))
check('admin email not on list -> request (no takeover)','msg=requested' in r.headers.get('Location','') or 'msg=notallowed' in r.headers.get('Location',''))
php('$r=BDACI_PA_Members::get_by_email("admin@example.org"); if($r){ BDACI_PA_Members::update($r->id,["status"=>"active","roles"=>["admin"]]); }')
s,r,q,loc=sso('google', lambda n: claims_google('admin@example.org', nonce=n, sub='a1'))
check('SSO into administrator account blocked','msg=notallowed' in r.headers.get('Location',''), r.headers.get('Location'))
php('$r=BDACI_PA_Members::get_by_email("admin@example.org"); if($r) BDACI_PA_Members::delete($r->id);')
# Microsoft
T='11111111-2222-3333-4444-555555555555'
def claims_ms(email, n, tid=T, extra=None):
    c={'iss':f'https://login.microsoftonline.com/{tid}/v2.0','aud':'ms-client','sub':'ms-sub','oid':'oid-1','tid':tid,'preferred_username':email,'name':'Cal Comm','exp':int(time.time())+600,'iat':int(time.time()),'nonce':n}
    c.update(extra or {}); return c
s,r,q,loc=sso('microsoft', lambda n: claims_ms('comm@example.org', n))
check('ms authorize URL', loc.startswith(f'https://login.microsoftonline.com/{T}/oauth2/v2.0/authorize'))
check('ms SSO signs in existing password user', r.status_code==302 and r.headers['Location']==B+'/board-portal/', r.headers.get('Location'))
s,r,q,loc=sso('microsoft', lambda n: claims_ms('comm@example.org', n, tid='99999999-2222-3333-4444-555555555555'))
check('ms other tenant rejected','msg=ssofailed' in r.headers.get('Location',''))
php('$s=BDACI_PA_Settings::all(); $s["ms_tenant"]="organizations"; BDACI_PA_Settings::save($s);')
s,r,q,loc=sso('microsoft', lambda n: claims_ms('comm@example.org', n, tid='99999999-2222-3333-4444-555555555555'))
check('multi-tenant w/o xms_edov rejected','msg=unverified' in r.headers.get('Location',''), r.headers.get('Location'))
s,r,q,loc=sso('microsoft', lambda n: claims_ms('comm@example.org', n, tid='99999999-2222-3333-4444-555555555555', extra={'xms_edov':True,'oid':'oid-other'}))
check('multi-tenant different oid for bound account rejected','msg=ssomismatch' in r.headers.get('Location',''), r.headers.get('Location'))
php('$s=BDACI_PA_Settings::all(); $s["ms_tenant"]="'+T+'"; BDACI_PA_Settings::save($s);')
# disabled provider
php('$s=BDACI_PA_Settings::all(); $s["google_enabled"]=0; BDACI_PA_Settings::save($s);')
r=requests.get(L, params={'sso':'google'}, allow_redirects=False); check('disabled provider refused','msg=ssodisabled' in r.headers.get('Location',''))
r=requests.get(L); check('disabled provider button hidden','Sign in with Google' not in r.text)
php('$s=BDACI_PA_Settings::all(); $s["google_enabled"]=1; BDACI_PA_Settings::save($s);')

# --- private documents
pdf=WP+'/../test.pdf'; open(pdf,'wb').write(b'%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'Board Agenda','level':'board'},files={'bdaci_file':('agenda.pdf',open(pdf,'rb'),'application/pdf')})
check('upload private doc', 'Uploaded.' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
did=int(re.search(DOCRE, r.text).group(1))
path=php(f'echo get_attached_file({did});')
check('stored in private dir', 'bdaci-portal-private-' in path and os.path.exists(path), path)
check('htaccess deny present', 'Require all denied' in open(os.path.dirname(os.path.dirname(path))+'/.htaccess').read())
url=php(f'echo wp_get_attachment_url({did});'); check('attachment URL is gated endpoint', 'bdaci_doc=' in url, url)
r=requests.get(B+f'/?bdaci_doc={did}',allow_redirects=False); check('anon doc -> login', r.status_code==302 and 'portal-login' in r.headers['Location'])
r=s2.get(B+f'/?bdaci_doc={did}',allow_redirects=False); check('committee denied board doc', r.status_code==403, r.status_code)
sb=requests.Session(); r=login_pw(sb,'board@example.org','BoardPass!2345xyz'); code=last_code('board@example.org'); sb.post(L,data={'code':code,'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')})
r=sb.get(B+f'/?bdaci_doc={did}'); check('board opens doc inline', r.status_code==200 and r.content.startswith(b'%PDF') and r.headers['Content-Disposition'].startswith('inline') and r.headers.get('X-Content-Type-Options')=='nosniff', (r.status_code, r.headers))
r=requests.get(B+f'/?rest_route=/wp/v2/media/{did}'); check('REST media single hidden', r.status_code in (401,404), r.status_code)
r=requests.get(B+'/?rest_route=/wp/v2/media'); check('REST media list hidden', all(m['id']!=did for m in r.json()))

# --- multi-file upload (drag/drop/paste sends bdaci_file[])
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
check('dropzone rendered', 'id="bdaci-dropzone"' in r.text and 'admin-upload.js' in r.text and 'name="bdaci_file[]"' in r.text)
open(WP+'/../notes.txt','w').write('minutes')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'','level':'portal'},files=[('bdaci_file[]',('minutes.pdf',open(pdf,'rb'),'application/pdf')),('bdaci_file[]',('notes.txt',open(WP+'/../notes.txt','rb'),'text/plain'))])
msgs=re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text)
check('multi upload 2 files', any('2' in m for m in msgs) and r.text.count('bdaci_document id=')>=3, msgs)
open(WP+'/../evil.php','w').write('<?php echo 1;')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'','level':'portal'},files=[('bdaci_file[]',('evil.php',open(WP+'/../evil.php','rb'),'text/plain'))])
msgs=re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text)
check('php upload rejected', not any('Uploaded' in m for m in msgs) and not php('echo count(glob(WP_CONTENT_DIR."/uploads/bdaci-portal-private-*/*/*/evil*"));').strip()!='0', msgs)

# --- v1.2: Finance Committee role + hidden sections
check('finance role exists', 'yes' in php('echo get_role("bdaci_finance_member") && get_role("bdaci_finance_member")->has_cap("bdaci_view_finance") ? "yes":"no";'))
check('admin has finance cap', 'yes' in php('echo get_role("administrator")->has_cap("bdaci_view_finance") ? "yes":"no";'))
out=php(r"""
$root=(int)BDACI_PA_Settings::get('portal_root_page');
foreach(['Finance Committee','Strategic Plan','Fin Child','Plan Child'] as $t){ $p=get_page_by_title($t); if($p) wp_delete_post($p->ID,true); }
$fin=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Finance Committee','post_name'=>'finance-committee','post_parent'=>$root,'post_content'=>'SECRET-FIN']);
update_post_meta($fin,'_bdaci_access','finance');
$fc=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Fin Child','post_name'=>'fin-child','post_parent'=>$fin,'post_content'=>'SECRET-FIN-CHILD']);
$sp=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Strategic Plan','post_name'=>'strategic-plan','post_parent'=>$root,'post_content'=>'SECRET-PLAN']);
update_post_meta($sp,'_bdaci_hidden','1');
$pc=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Plan Child','post_name'=>'plan-child','post_parent'=>$sp,'post_content'=>'SECRET-PLAN-CHILD']);
update_post_meta($pc,'_bdaci_access','board');
BDACI_PA_Access::flush_cache();
$r=BDACI_PA_Members::get_by_email('fin@example.org'); if($r) BDACI_PA_Members::delete($r->id);
$u=get_user_by('email','fin@example.org'); if(!$u){ $uid=wp_insert_user(['user_login'=>'finmember','user_email'=>'fin@example.org','user_pass'=>'FinPass!2345xyz','display_name'=>'Fin Member','role'=>'']); } else { $uid=$u->ID; wp_set_password('FinPass!2345xyz',$uid); }
$id=BDACI_PA_Members::add('fin@example.org','Fin Member',['finance']); BDACI_PA_Members::apply_to_account($id);
echo json_encode(['fin'=>$fin,'sp'=>$sp,'roles'=>get_userdata($uid)->roles]);
""")
ids=json.loads(out.strip().splitlines()[-1]); check('finance member gets role', 'bdaci_finance_member' in ids['roles'], ids)
FIN=B+'/board-portal/finance-committee/'; FINC=FIN+'fin-child/'; PLAN=B+'/board-portal/strategic-plan/'; PLANC=PLAN+'plan-child/'
sf=requests.Session(); r=login_pw(sf,'fin@example.org','FinPass!2345xyz'); code=last_code('fin@example.org'); sf.post(L,data={'code':code,'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')})
r=sf.get(FIN); check('finance member sees finance page','SECRET-FIN' in r.text, r.status_code)
r=sf.get(FINC); check('finance member sees finance child','SECRET-FIN-CHILD' in r.text)
r=sf.get(B+'/board-portal/board-only/',allow_redirects=False); check('finance member blocked from board page', r.status_code==403)
r=sf.get(B+'/board-portal/education-committee/',allow_redirects=False); check('finance member blocked from education', r.status_code==403)
r=sb.get(FIN); check('board sees finance page','SECRET-FIN' in r.text)
r=s2.get(FIN,allow_redirects=False); check('education member blocked from finance', r.status_code==403, r.status_code)
r=a.get(FIN,allow_redirects=False); check('anon finance -> login', r.status_code==302 and 'portal-login' in r.headers['Location'])
for who,ses in [('anon',a),('board',sb),('education',s2),('finance',sf)]:
    r=ses.get(PLAN,allow_redirects=False); check(f'hidden section 404 for {who}', r.status_code==404 and 'SECRET-PLAN' not in r.text, r.status_code)
    r=ses.get(PLANC,allow_redirects=False); check(f'hidden child 404 for {who}', r.status_code==404 and 'SECRET-PLAN-CHILD' not in r.text, r.status_code)
r=s4.get(PLAN); check('admin sees hidden section','SECRET-PLAN' in r.text, r.status_code)
r=s4.get(PLANC); check('admin sees hidden child','SECRET-PLAN-CHILD' in r.text)
r=sb.get(B+'/?rest_route=/wp/v2/pages/%d'%ids['sp']); check('hidden REST 404 for board', r.status_code in (401,403,404), r.status_code)
r=sb.get(B+'/?s=SECRET-PLAN'); check('hidden not in board search','Strategic Plan' not in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-sections'); check('sections page lists hidden', 'Portal Sections' in r.text and 'Strategic Plan' in r.text and 'Hidden by parent' in r.text, r.status_code)
m=re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="bdaci_pa_section_visibility"><input type="hidden" name="id" value="%d"><input type="hidden" name="hide" value="0">'%ids['sp'], r.text)
check('show button for hidden section', bool(m))
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_section_visibility','_wpnonce':m.group(1) if m else '','id':ids['sp'],'hide':'0'})
check('show section via button', 'is now visible' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
r=sb.get(PLAN); check('board sees section after show','SECRET-PLAN' in r.text, r.status_code)
r=sb.get(PLANC); check('board sees child after show (board level)','SECRET-PLAN-CHILD' in r.text)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_section_visibility','_wpnonce':'bad','id':ids['sp'],'hide':'1'}); check('visibility bad nonce rejected', r.status_code in (403,200) and 'is now hidden' not in r.text)
r=sb.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_section_visibility','_wpnonce':'x','id':ids['sp'],'hide':'1'}); check('board cannot hide sections', 'is now hidden' not in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-log'); check('visibility logged','section_shown' in r.text)
# private doc at finance level
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
check('docs page offers finance level','name="who[]" value="finance"' in r.text)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'Fin Statement','level':'finance'},files={'bdaci_file':('fin.pdf',open(pdf,'rb'),'application/pdf')})
print('  upload msg:', re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text)); fid=max(int(x) for x in re.findall(DOCRE, r.text))
check('finance doc stored with finance level', php(f'echo get_post_meta({fid},"_bdaci_access",true);').strip()=='finance', fid)
r=sf.get(B+f'/?bdaci_doc={fid}'); check('finance member opens finance doc', r.status_code==200 and r.content.startswith(b'%PDF'))
r=s2.get(B+f'/?bdaci_doc={fid}',allow_redirects=False); check('education member denied finance doc', r.status_code==403, r.status_code)

# ======================= v2.0 portal features =======================
P=B+'/board-portal'
def fnonce_with(html, action, needle):
    for f in re.findall(r'<form.*?</form>', html, re.S):
        if f'name="action" value="{action}"' in f and needle in f:
            return re.search(r'name="_wpnonce" value="([^"]+)"', f).group(1)
def portal_post(ses, page_html, do, data):
    d={'action':'bdaci_pa_portal','do':do,'_bdaci_nonce':nonce(page_html, do) or ''}; d.update(data)
    return ses.post(B+'/wp-admin/admin-post.php', data=d)
# setup pages (idempotent) via admin button
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_setup_pages')
if n: s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_setup_pages','_wpnonce':n})
smap=json.loads(php('echo json_encode(BDACI_PA_Portal::screen_map());').strip().splitlines()[-1])
check('portal pages created', all(k in smap for k in ['dashboard','calendar','meetings','help','profile','leadership','governance','orientation','qa','education','finance']), smap)
r=a.get(P+'/calendar/',allow_redirects=False); check('anon portal screen -> login', r.status_code==302)
# meetings
php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_meetings"); $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_rsvps");')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-meetings'); n=fnonce(r.text,'bdaci_pa_meeting_save')
import datetime; d1=(datetime.date.today()+datetime.timedelta(days=10)).isoformat(); d2=(datetime.date.today()+datetime.timedelta(days=12)).isoformat()
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_meeting_save','_wpnonce':n,'grp':'board','title':'Board Test Meeting','date':d1,'start':'18:00','end':'20:00','location':'Boardroom','video_url':'https://teams.microsoft.com/l/x'})
check('admin adds board meeting','Meeting added' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_meeting_save','_wpnonce':n,'grp':'edu','title':'Edu Test Meeting','date':d2,'start':'17:00','video_url':'http://insecure.example/'})
check('non-https video link refused','https://' in r.text and 'Meeting added' not in r.text)
s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_meeting_save','_wpnonce':n,'grp':'edu','title':'Edu Test Meeting','date':d2,'start':'17:00'})
r=sb.get(P+'/'); check('board dashboard shows next board meeting','Board Test Meeting' in r.text and 'Join video call' in r.text)
r=sb.get(P+'/calendar/?cal='+d1[:7]); check('board calendar hides education meeting','Board Test Meeting' in r.text and 'Edu Test Meeting' not in r.text)
r=s2.get(P+'/calendar/?cal='+d2[:7]); check('education member calendar shows edu meeting','Edu Test Meeting' in r.text and 'Board Test Meeting' not in r.text)
mid=int(php('global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bdaci_pa_meetings WHERE grp=\'board\'");').strip())
eid=int(php('global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bdaci_pa_meetings WHERE grp=\'edu\'");').strip())
r=sb.get(P+'/'); r=portal_post(sb, r.text, 'rsvp', {'meeting':mid,'response':'attending'})
check('board RSVP saved', php(f'echo BDACI_PA_Meetings::rsvp({mid}, get_user_by("email","board@example.org")->ID)->response ?? "";').strip() =='attending', php(f'var_export(BDACI_PA_Meetings::rsvp({mid}, get_user_by("email","board@example.org")->ID));'))
r=portal_post(sb, sb.get(P+'/').text, 'rsvp', {'meeting':eid,'response':'attending'})
check('cannot RSVP to a meeting you are not invited to', not php(f'echo BDACI_PA_Meetings::rsvp({eid}, get_user_by("email","board@example.org")->ID) ? "x":"";').strip())
r=sb.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_portal','do':'rsvp','_bdaci_nonce':'bad','meeting':mid,'response':'no'}); check('portal form bad nonce rejected', r.status_code==403)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-meetings&replies=%d'%mid); check('admin sees RSVP replies','Bea Board' in r.text or 'board@example.org' in r.text)
# documents: sections + who + hidden folder
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'EDU-V2-AGENDA','sections[]':['edu','agenda'],'who[]':['committee'],'sections_sent':'1','doc_date':'2026-03-04'},files={'bdaci_file':('e.pdf',open(pdf,'rb'),'application/pdf')})
check('upload with sections + who', 'Uploaded.' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
eduid=int(php('$p=get_posts(["post_type"=>"attachment","post_status"=>"inherit","title"=>"EDU-V2-AGENDA","numberposts"=>1,"suppress_filters"=>true,"bdaci_pa_all"=>true]); echo $p[0]->ID;').strip())
check('doc level/sections/date stored', php(f'echo get_post_meta({eduid},"_bdaci_access",true),"|",get_post_meta({eduid},"_bdaci_sections",true),"|",get_post_meta({eduid},"_bdaci_doc_date",true);').strip()=='committee|edu,agenda|2026-03-04')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'X','sections_sent':'1','who[]':['board']},files={'bdaci_file':('x.pdf',open(pdf,'rb'),'application/pdf')})
check('upload without a section refused','Pick at least one section' in r.text)
r=s2.get(P+'/education-committee/'); check('education page lists the doc','EDU-V2-AGENDA' in r.text)
r=sb.get(P+'/'); check('board dashboard does not list committee-only doc','EDU-V2-AGENDA' not in r.text)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'STRAT-V2','sections[]':['strategic'],'who[]':['board'],'sections_sent':'1'},files={'bdaci_file':('s.pdf',open(pdf,'rb'),'application/pdf')})
sid=int(php('$p=get_posts(["post_type"=>"attachment","post_status"=>"inherit","title"=>"STRAT-V2","numberposts"=>1,"suppress_filters"=>true,"bdaci_pa_all"=>true]); echo $p[0]->ID;').strip())
r=sb.get(B+f'/?bdaci_doc={sid}',allow_redirects=False); check('doc in hidden folder blocked for board', r.status_code in (403,404), r.status_code)
r=s4.get(B+f'/?bdaci_doc={sid}'); check('admin opens doc in hidden folder', r.status_code==200)
r=sb.get(P+'/governance-core-documents/'); check('hidden folder not shown to board','Strategic Plan' not in r.text and 'STRAT-V2' not in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n2=fnonce_with(r.text,'bdaci_pa_folder_visibility','value="strategic"')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_folder_visibility','_wpnonce':n2 or '','id':'strategic','hide':'0'})
r=sb.get(B+f'/?bdaci_doc={sid}',allow_redirects=False); check('folder shown -> board can open', r.status_code==200, r.status_code)
php('BDACI_PA_Sections::set_hidden("strategic", true);')
# custom section
php('foreach(BDACI_PA_Sections::custom() as $k=>$c){ if(stripos(json_encode($c),"Audit Committee")!==false) BDACI_PA_Sections::delete_custom($k); }')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_section_create')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_section_create','_wpnonce':n,'label':'Audit Committee','menu':'committees','who[]':['board']})
r=sb.get(P+'/'); check('new section in board sidebar','Audit Committee' in r.text)
r=s2.get(P+'/'); check('new board-only section marked restricted for committee','Audit Committee' in r.text and 'Restricted' in r.text)
# access requests
php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_requests");')
r=sb.get(P+'/education-committee/'); check('board sees request-access form', r.status_code==403 and 'Request access' in r.text, r.status_code)
r=portal_post(sb, r.text, 'request', {'area':'education','reason':'Joining in January'})
check('request recorded', php('echo BDACI_PA_Requests::pending_count();').strip()=='1')
check('admin emailed about request', 'Access request' in open(WP+'/wp-content/mail.log').read().splitlines()[-1])
r=sb.get(P+'/education-committee/'); check('pending request shown','Requested' in r.text or 'with the portal administrator' in r.text)
rid=int(php('global $wpdb; echo $wpdb->get_var("SELECT id FROM {$wpdb->prefix}bdaci_pa_requests WHERE status=\'pending\'");').strip())
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal'); check('request listed on Authorized Users','Joining in January' in r.text)
n=fnonce_with(r.text,'bdaci_pa_request_decide','value="%d"'%rid)
r=sb.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_request_decide','_wpnonce':n,'id':rid,'do':'approve'}); check('board cannot approve requests', r.status_code==403)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_request_decide','_wpnonce':n,'id':rid,'do':'approve'})
check('admin approves request','can now open' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
r=sb.get(P+'/education-committee/'); check('approved access works', r.status_code==200 and 'SECRET-EDU' in r.text, r.status_code)
check('approval emailed', 'You now have access' in open(WP+'/wp-content/mail.log').read().splitlines()[-1])
php('$u=get_user_by("email","board@example.org"); $m=BDACI_PA_Members::for_user($u->ID); BDACI_PA_Members::update($m->id,["roles"=>"board"]); BDACI_PA_Roles::sync_user($u->ID,["board"]);')
# sign-offs
php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_signoff_events");')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'title':'ORIENT-V2','sections[]':['orientation'],'who[]':['board'],'sections_sent':'1'},files={'bdaci_file':('o.pdf',open(pdf,'rb'),'application/pdf')})
r=sb.get(P+'/new-director-orientation/'); odocs=sorted(set(re.findall(r'bdaci_doc=(\d+)', r.text)))
check('orientation lists documents', len(odocs)>=1, odocs)
r=portal_post(sb, r.text, 'signoff', {'pack':'orientation','ack':'1'})
check('sign-off refused before opening docs', php('echo BDACI_PA_Signoffs::status("orientation", get_user_by("email","board@example.org")->ID)["signed"] ? "y":"n";').strip()=='n')
for d in odocs: sb.get(B+f'/?bdaci_doc={d}')
r=sb.get(P+'/new-director-orientation/'); check('opened docs tracked', 'Not opened' not in r.text)
r=portal_post(sb, r.text, 'signoff', {'pack':'orientation','ack':'1'})
check('sign-off recorded', php('echo BDACI_PA_Signoffs::status("orientation", get_user_by("email","board@example.org")->ID)["signed"] ? "y":"n";').strip()=='y')
check('sign-off confirmation emailed', 'board@example.org' in open(WP+'/wp-content/mail.log').read().splitlines()[-1])
r=s2.get(P+'/new-director-orientation/',allow_redirects=False); check('education member cannot open orientation', r.status_code in (403,404))
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-signoffs&pack=orientation'); check('audit trail shows signature','Bea Board' in r.text and 'Signed' in r.text)
check('audit trail shows signed/sent times', re.search(r'(Signed|Sent) [A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2} [AP]M', r.text) is not None)
r=s4.get(B+'/wp-admin/admin-post.php?action=bdaci_pa_signoff_export&pack=orientation&format=csv&_wpnonce='+(re.search(r'bdaci_pa_signoff_export[^"]*_wpnonce=([0-9a-f]+)', r.text.replace('&amp;','&')) or re.search('x','x')).group(1) if re.search(r'bdaci_pa_signoff_export[^"]*_wpnonce=([0-9a-f]+)', r.text.replace('&amp;','&')) else B)
check('audit CSV export', 'text/csv' in r.headers.get('Content-Type','') and 'board@example.org' in r.text, r.headers.get('Content-Type'))
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-signoffs&pack=qa'); n=fnonce_with(r.text,'bdaci_pa_signoff_admin','value="remind"') or fnonce_with(r.text,'bdaci_pa_signoff_admin','value="send"')
# profile
r=sb.get(P+'/my-profile/'); r=portal_post(sb, r.text, 'photo', {'mode':'preset','preset':'p7'})
check('preset picture saved', php('echo get_user_meta(get_user_by("email","board@example.org")->ID,"bdaci_avatar",true);').strip()=='preset:p7')
r=sb.get(P+'/'); check('preset shows in top bar','avatars/p7.svg' in r.text)
r=portal_post(sb, sb.get(P+'/my-profile/').text, 'photo', {'mode':'preset','preset':'../../evil'})
check('bad preset ignored', php('echo get_user_meta(get_user_by("email","board@example.org")->ID,"bdaci_avatar",true);').strip()=='preset:p7')
png=bytes.fromhex('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082')
r=sb.get(P+'/my-profile/'); d={'action':'bdaci_pa_portal','do':'photo','mode':'upload','_bdaci_nonce':nonce(r.text,'photo')}
r=sb.post(B+'/wp-admin/admin-post.php',data=d,files={'photo':('me.png',png,'image/png')})
uid=php('echo get_user_by("email","board@example.org")->ID;').strip()
check('photo uploaded', php(f'echo get_user_meta({uid},"bdaci_avatar",true);').strip().startswith('upload:'), php(f'echo get_user_meta({uid},"bdaci_avatar",true);'))
r=sb.get(B+f'/?bdaci_avatar={uid}'); check('member can load photo', r.status_code==200 and r.content[:4]==b'\x89PNG', r.status_code)
r=requests.get(B+f'/?bdaci_avatar={uid}',allow_redirects=False); check('anon cannot load photo', r.status_code in (302,403,404), r.status_code)
r=portal_post(sb, sb.get(P+'/my-profile/').text, 'photo', {'mode':'initials'})
check('back to initials', php(f'echo get_user_meta({uid},"bdaci_avatar",true);').strip()=='')
r=portal_post(sb, sb.get(P+'/my-profile/').text, 'profile', {'phone':'613-555-0100','show_phone':'1'})
r=s2.get(P+'/leadership/'); check('shared phone on leadership', '613-555-0100' in r.text)
# support
r=sb.get(P+'/support/'); r=portal_post(sb, r.text, 'help', {'topic':'access','message':'Cannot open the agenda'})
check('support message emailed', 'Cannot open the agenda' in open(WP+'/wp-content/mail.log').read().splitlines()[-1])
# directory details (admin)
r=s4.get(B+f'/wp-admin/admin.php?page=bdaci-portal-person&user={uid}'); check('directory details page', r.status_code==200 and 'Bea Board' in r.text)

# ======================= v2.1 test-data tool =======================
def counts():
    return json.loads(php('global $wpdb; $p=$wpdb->prefix; echo json_encode(["users"=>count(get_users(["meta_key"=>"_bdaci_pa_demo"])),"members"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_members"),"meetings"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_meetings"),"docs"=>count(BDACI_PA_Documents::all()),"rsvps"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_rsvps"),"req"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_requests"),"so"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_signoff_events"),"log"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bdaci_pa_log"),"admin"=>user_can(1,"manage_options")]);').strip().splitlines()[-1])
php('BDACI_PA_Demo::clear();')
before=counts()
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-test-data'); check('test data screen', r.status_code==200 and 'Add sample data' in r.text)
n=fnonce(r.text,'bdaci_pa_demo_add')
r=sb.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_add','_wpnonce':n}); check('board cannot add test data', r.status_code==403)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_add','_wpnonce':n}); check('add sample data', 'Added 6 test people, 5 meetings and 14 documents' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
c1=counts(); check('first batch counts', c1['users']==before['users']+6 and c1['meetings']==before['meetings']+5 and c1['docs']==before['docs']+14 and c1['rsvps']>before['rsvps'] and c1['req']==before['req']+1 and c1['so']>before['so'], (before,c1))
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_add','_wpnonce':n}); c2=counts(); check('second press adds more', c2['users']==c1['users']+6 and c2['meetings']==c1['meetings']+5, c2)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-test-data'); pw=re.search(r'password <code>([^<]+)</code>', r.text).group(1); em=re.search(r'<code>([^<]*\+bdaci-test-margaret-hughes@[^<]*)</code>', r.text).group(1)
check('test people listed with password', bool(pw) and 'Margaret Hughes' in r.text)
st=requests.Session(); rr=st.get(L); rr=st.post(L,data={'log':em,'pwd':pw,'bdaci_step':'password','_bdaci_nonce':nonce(rr.text,'password')}); code=last_code(em); st.post(L,data={'code':code,'bdaci_step':'code','_bdaci_nonce':nonce(rr.text,'code')})
r=st.get(P+'/'); check('test person can sign in and sees dashboard', 'Good' in r.text and 'Margaret' in r.text, r.status_code)
r=st.get(P+'/leadership/'); check('test people in leadership directory','President' in r.text and 'Daniel Okafor' in r.text)
r=st.get(P+'/governance-core-documents/'); check('test docs in governance','BDACI Bylaws' in r.text)
did=int(php('$s=BDACI_PA_Demo::state(); echo $s["docs"][0];').strip())
r=st.get(B+f'/?bdaci_doc={did}'); check('test PDF opens', r.status_code==200 and r.content.startswith(b'%PDF'), r.status_code)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal'); check('test request awaits approval','test request' in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-signoffs&pack=orientation'); check('test sign-off activity shown','Margaret Hughes' in r.text)
paths=php('$s=BDACI_PA_Demo::state(); foreach($s["docs"] as $d) echo get_attached_file($d),"\n";').split()
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-test-data'); n=fnonce(r.text,'bdaci_pa_demo_clear')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_clear','_wpnonce':n}); check('remove test data', 'Removed 12 test people, 10 meetings and 28 documents' in r.text, re.findall(r'notice-\w+[^<]*<p>([^<]*)',r.text))
c3=counts(); check('back to before (people, meetings, docs, requests)', all(c3[k]==before[k] for k in ['users','members','meetings','docs','req']) and c3['rsvps']==before['rsvps'] and c3['so']==before['so'], (before,c3))
check('test files deleted from disk', paths and not any(os.path.exists(p) for p in paths))
check('real people kept', php('echo get_user_by("email","board@example.org") ? "y":"n";').strip()=='y' and c3['admin'])
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_add','_wpnonce':fnonce(s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-test-data').text,'bdaci_pa_demo_add')})
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-test-data'); n=fnonce(r.text,'bdaci_pa_demo_launch')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_launch','_wpnonce':n,'confirm':'nope','with_test_data':'1'}); check('launch reset needs RESET', 'Type RESET' in r.text and counts()['users']==before['users']+6)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_demo_launch','_wpnonce':n,'confirm':'RESET','with_test_data':'1'}); c4=counts()
check('launch reset clears activity and test data', 'launch state' in r.text and c4['users']==before['users'] and c4['rsvps']==0 and c4['so']==0 and c4['req']==0 and c4['log']<=2 and c4['meetings']==before['meetings'] and c4['docs']==before['docs'], c4)

# ======================= v2.2 admin screens inside the portal =======================
r=s4.get(P+'/'); check('admin sidebar links to portal admin screens', 'manage=users' in r.text and 'manage=docs' in r.text and 'manage=meetings' in r.text and 'manage=signoffs' in r.text)
for k,txt in [('users','Add a person'),('docs','Upload a document'),('meetings','Add a meeting'),('signoffs','Everyone at a glance'),('test','Add sample data')]:
    r=s4.get(P+'/?manage='+k); check(f'portal admin screen {k}', r.status_code==200 and txt in r.text and 'class="bp"' in r.text and 'aria-current="page"' in r.text)
for ses,who in [(sb,'board'),(s2,'education')]:
    r=ses.get(P+'/?manage=users'); check(f'{who} member gets no admin screen', 'Add a person' not in r.text and 'Authorized Users' not in r.text and 'Good' in r.text)
r=s4.get(P+'/?manage=users'); n=fnonce(r.text,'bdaci_pa_member_add'); check('portal form marked as portal', 'name="_bdaci_front"' in r.text)
php('$r=BDACI_PA_Members::get_by_email("front@example.org"); if($r) BDACI_PA_Members::delete($r->id);')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_add','_wpnonce':n,'_bdaci_front':'1','email':'front@example.org','display_name':'Front Person','roles[]':['board']})
check('add person from portal returns to portal', 'manage=users' in r.url and 'front@example.org' in r.text and 'notice-bar' in r.text, r.url)
rid=php('echo BDACI_PA_Members::get_by_email("front@example.org")->id;').strip()
n=fnonce_with(r.text,'bdaci_pa_member_roles','value="%s"'%rid)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_roles','_wpnonce':n,'_bdaci_front':'1','id':rid,'roles[]':['board','finance']})
check('change roles from portal', 'manage=users' in r.url and php(f'echo BDACI_PA_Members::get({rid})->roles;').strip()=='board,finance')
n=fnonce_with(r.text,'bdaci_pa_member_action','value="%s"'%rid)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_member_action','_wpnonce':n,'_bdaci_front':'1','id':rid,'do':'delete'}); check('remove person from portal', 'manage=users' in r.url and not php('echo BDACI_PA_Members::get_by_email("front@example.org") ? "y":"";').strip())
r=s4.get(P+'/?manage=meetings'); n=fnonce(r.text,'bdaci_pa_meeting_save')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_meeting_save','_wpnonce':n,'_bdaci_front':'1','grp':'board','title':'Front Meeting','date':d1,'start':'09:00'})
check('add meeting from portal returns to portal', 'manage=meetings' in r.url and 'Front Meeting' in r.text, r.url)
r=s4.get(P+'/?manage=docs'); n=fnonce(r.text,'bdaci_pa_doc_upload'); check('portal docs screen loads uploader', 'admin-upload.js' in r.text)
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'_bdaci_front':'1','title':'FRONT-DOC','sections[]':['agenda'],'who[]':['board'],'sections_sent':'1'},files={'bdaci_file':('f.pdf',open(pdf,'rb'),'application/pdf')})
check('upload from portal returns to portal', 'manage=docs' in r.url and 'Uploaded.' in r.text, r.url)
r=s4.get(P+'/?manage=signoffs&pack=qa'); check('portal sign-off tabs stay in portal', 'manage=signoffs' in r.text and 'pack=orientation' in r.text)
uid=php('echo get_user_by("email","board@example.org")->ID;').strip()
r=s4.get(P+f'/?manage=person&user={uid}'); n=fnonce(r.text,'bdaci_pa_person_save')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_person_save','_wpnonce':n,'_bdaci_front':'1','user':uid,'display_name':'Bea Board','bdaci_position':'President'})
check('directory details from portal', 'manage=person' in r.url and 'Directory details saved' in r.text, r.url)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-meetings'); n=fnonce(r.text,'bdaci_pa_meeting_save')
r=s4.post(B+'/wp-admin/admin-post.php',data={'action':'bdaci_pa_meeting_save','_wpnonce':n,'grp':'board','title':'Admin Meeting','date':d1,'start':'09:00'})
check('wp-admin forms still return to wp-admin', 'wp-admin/admin.php?page=bdaci-portal-meetings' in r.url, r.url)
php('global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}bdaci_pa_meetings WHERE title IN (\'Front Meeting\',\'Admin Meeting\')");')

# ======================= v2.3 grouped section picker with maps =======================
r=s4.get(P+'/?manage=docs')
for g in ['Meeting documents','Governance &amp; Core Documents','Board sign-offs','Committee pages']:
    check('section group shown: '+g, g in r.text)
check('each group has a mini portal map', r.text.count('class="mm')>=4)
check('cards say where they show', 'Shows on:' in r.text)
check('group maps show each section spot on the page', 'data-spot="bylaws"' in r.text and 'Board Policies folder' in r.text)
check('maps can be zoomed', 'mm-dialog' in r.text and r.text.count('mm-dialog')>=1)
check('live "where this file will appear" panel', 'id="bdaci-shows"' in r.text and 'Where this file will appear' in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); check('wp-admin uploader has grouped picker and panel', 'secgroup' in r.text and 'id="bdaci-shows"' in r.text)

# ======================= v2.5 sign-in page layout =======================
r=requests.get(B+'/portal-login/'); check('sign-in page has brand panel with illustration', 'bdaci-art' in r.text and 'signin-art.svg' in r.text and 'We all have value.' in r.text)
check('sign-in page uses bundled logo when none set', 'logo-full.png' in r.text or 'bdaci-logo' in r.text)
r=s4.get(B+'/portal-login/?preview=1', allow_redirects=False); check('admin can preview sign-in page while signed in', r.status_code==200 and 'bdaci-previewbar' in r.text)
r=sb.get(B+'/portal-login/?preview=1', allow_redirects=False); check('non-admin preview request still redirects', r.status_code in (301,302))

# ======================= v2.6 QA Testing page =======================
AP=B+'/wp-admin/admin-post.php'
r=s4.get(P+'/?manage=qa'); check('QA page loads in portal for admin', 'QA Testing' in r.text and 'qa-groups' in r.text and 'SIGN-01' in r.text and 'Progress by group' in r.text)
check('admin sidebar links QA Testing', 'manage=qa' in s4.get(P+'/').text)
r=s4.get(P+'/?manage=qa&group=sign'); check('group tab shows only that group', 'SIGN-01' in r.text and 'ACC-01' not in r.text)
n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-01"'); rid=re.search(r'name="round" value="(\d+)"',r.text).group(1)
r=s4.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-01','round':rid,'status':'pass','note':'Looks good','group':'sign','show':''})
check('mark pass returns to QA group in portal', 'manage=qa' in r.url and 'group=sign' in r.url and 'SIGN-01 marked Passed' in r.text and 'Passed by' in r.text, r.url)
r=sb.get(P+'/?manage=qa'); check('board member without access cannot open QA', 'qa-case' not in r.text and '>QA Testing<' not in r.text)
rb=sb.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'scenario':'SIGN-02','round':rid,'status':'pass'}); check('board member cannot post a mark', rb.status_code in (403,) or 'SIGN-02' not in php('global $wpdb; echo implode(",", $wpdb->get_col("SELECT scenario FROM {$wpdb->prefix}bdaci_pa_qa_log"));'))
buid=php('echo get_user_by("email","board@example.org")->ID;').strip()
r=s4.get(P+'/?manage=qa&view=access'); check('access view lists members', 'Who can see this page' in r.text and 'board@example.org' in r.text)
n=fnonce(r.text,'bdaci_pa_qa_access'); r=s4.post(AP,data={'action':'bdaci_pa_qa_access','_wpnonce':n,'_bdaci_front':'1','users[]':[buid]})
check('save QA access', 'view=access' in r.url and 'Saved. 1 member' in r.text, r.url)
r=sb.get(P+'/'); check('allowed member sees QA Testing in side menu', '>QA Testing<' in r.text)
r=sb.get(P+'/?manage=qa&group=sign'); check('allowed member opens QA but not admin tabs', 'SIGN-01' in r.text and 'view=access' not in r.text)
n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-02"')
r=sb.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-02','round':rid,'status':'fail','note':'Code email slow','group':'sign'})
check('allowed member marks fail with note', 'SIGN-02 marked Failed' in r.text and 'Code email slow' in r.text)
r=s4.get(P+'/?manage=qa&view=record'); check('QA record lists both checks with testers', 'SIGN-01' in r.text and 'SIGN-02' in r.text and 'Code email slow' in r.text)
r=s4.get(P+f'/?manage=qa&view=record&round={rid}&tester={buid}'); check('QA record filters by tester', 'SIGN-02' in r.text and 'Looks good' not in r.text)
r=s4.get(P+'/?manage=qa'); csvu=re.search(r'href="([^"]*bdaci_pa_qa_csv[^"]*)"',r.text).group(1).replace('&amp;','&').replace('&#038;','&')
c=s4.get(csvu); check('CSV export has results', c.status_code==200 and 'SIGN-01' in c.text and 'Passed' in c.text and 'Code email slow' in c.text)
n=fnonce(r.text,'bdaci_pa_qa_round'); r=s4.post(AP,data={'action':'bdaci_pa_qa_round','_wpnonce':n,'_bdaci_front':'1','name':'Round 2 - retest'})
check('start a new round resets progress', 'Started Round 2 - retest' in r.text and '<b>0</b>/' in r.text)
nb=fnonce(sb.get(P+'/?manage=qa&view=access').text or '','bdaci_pa_qa_round')
check('member cannot start rounds', nb is None)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-qa'); check('QA page in wp-admin', 'QA Testing' in r.text and 'qa-groups' in r.text)
php('update_option("bdaci_pa_qa_users", array());')

# ======================= v2.7 notes, screenshots, breadcrumbs =======================
import struct, zlib
def tiny_png():
    raw=b'\x00\xff\x00\x00'
    def ch(k,d): return struct.pack('>I',len(d))+k+d+struct.pack('>I',zlib.crc32(k+d)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+ch(b'IHDR',struct.pack('>IIBBBBB',1,1,8,2,0,0,0))+ch(b'IDAT',zlib.compress(raw))+ch(b'IEND',b'')
r=s4.get(P+'/?manage=qa&group=sign')
check('scenario shows where to find it', 'Where to find it:' in r.text and 'class="qa-where"' in r.text and 'Sign-in page' in r.text)
r=s4.get(P+'/?manage=qa&group=access')
check('where link opens the Finance Committee page', re.search(r'qa-where.*?Finance Committee</li></ol><a class="btn btn-sm" href="[^"]*finance', r.text, re.S) is not None)
r=s4.get(P+'/?manage=qa'); check('QA page breadcrumb: Home > Portal admin > QA Testing', 'crumbs-bar' in r.text and re.search(r'crumbs-bar.*?Home</a></li><li>Portal admin</li><li aria-current="page">QA Testing</li>', r.text, re.S) is not None)
gov=[m for m in re.findall(r'href="([^"]+)"', sb.get(P+'/').text) if 'governance' in m]
r=sb.get(gov[0]) if gov else None
check('member page breadcrumb shows its menu group', r is not None and re.search(r'crumbs-bar.*?Home</a></li><li>Board</li><li aria-current="page">Governance', r.text, re.S) is not None)
check('home page has no breadcrumb', 'crumbs-bar' not in sb.get(P+'/').text)
r=s4.get(P+'/?manage=qa&group=sign'); rid=re.search(r'name="round" value="(\d+)"',r.text).group(1)
check('Skipped button is not hidden by the skip-link style', 'qa-mark st-skip' in r.text and 'class="qa-mark skip"' not in r.text)
check('card has screenshot upload and note-only button', 'name="shots[]"' in r.text and 'value="note"' in r.text and 'enctype="multipart/form-data"' in r.text)
n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-03"')
r=s4.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-03','round':rid,'status':'note','note':'Error text is small on phones','group':'sign'},files=[('shots[]',('shot.png',tiny_png(),'image/png'))])
check('save note only with a screenshot', 'Note saved on SIGN-03' in r.text and '1 screenshot added' in r.text and 'Error text is small on phones' in r.text, r.url)
card=re.search(r'id="qa-SIGN-03".*?</article>', r.text, re.S).group(0)
check('note only does not change the result', 'Not tested yet' in card and 'bdaci_pa_qa_shot' in card)
shot=re.search(r'<img src="([^"]*bdaci_pa_qa_shot[^"]*)"', card).group(1).replace('&amp;','&').replace('&#038;','&')
g=s4.get(shot); check('screenshot opens for QA viewers', g.status_code==200 and g.headers.get('Content-Type','').startswith('image/png') and g.content[:4]==b'\x89PNG')
g=requests.get(shot, allow_redirects=False); check('screenshot hidden when signed out', not g.content.startswith(b'\x89PNG'))
g=sb.get(shot); check('screenshot hidden from members without QA access', g.status_code==403 and not g.content.startswith(b'\x89PNG'))
d=php('echo BDACI_PA_QA::shot_dir();').strip(); check('screenshots stored in the protected folder', os.path.isdir(d) and 'bdaci-portal-private-' in d and any(f.endswith('.png') for f in os.listdir(d)))
r=s4.get(P+'/?manage=qa&group=sign'); n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-04"')
r=s4.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-04','round':rid,'status':'note','note':'','group':'sign'})
check('empty note is refused', 'Type a note or add a screenshot first' in r.text)
r=s4.get(P+'/?manage=qa&group=sign'); n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-04"')
r=s4.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-04','round':rid,'status':'fail','note':'Old code still accepted','group':'sign'},files=[('shots[]',('a.png',tiny_png(),'image/png')),('shots[]',('evil.png',b'<?php echo 1; ?>','image/png'))])
check('fail with screenshot; non-picture refused', 'SIGN-04 marked Failed' in r.text and '1 screenshot added' in r.text and 'is not a picture' in r.text)
r=s4.get(P+'/?manage=qa&view=record'); check('QA record shows screenshots and notes', 'Note and screenshots' in r.text and 'bdaci_pa_qa_shot' in r.text and 'Note added' in r.text)
r=s4.get(P+'/?manage=qa&view=record&result=note'); check('QA record filters notes', 'SIGN-03' in r.text and 'Old code still accepted' not in r.text)
r=s4.get(P+'/?manage=qa'); csvu=re.search(r'href="([^"]*bdaci_pa_qa_csv[^"]*)"',r.text).group(1).replace('&amp;','&').replace('&#038;','&')
c=s4.get(csvu); check('CSV has where-to-find and screenshot links', 'Where to find it' in c.text and 'Sign-in page' in c.text and 'bdaci_pa_qa_shot' in c.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-qa'); check('wp-admin screen shows breadcrumb', 'bdaci-wp-crumbs' in r.text and 'BDACI Portal ›' in r.text)

# ======================= v2.8 section search, default date, times =======================
r=s4.get(P+'/?manage=docs')
import datetime as _dt
today=php('echo current_time("Y-m-d");').strip()
check('section search bar on Sections & Uploads', 'class="sec-q"' in r.text and 'Search sections' in r.text and 'sec-none' in r.text)
check('document date defaults to today', f'id="bdaci-date" name="doc_date" value="{today}"' in r.text)
check('admin document list shows upload date and time', re.search(r'uploaded by [^<]+ on [A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M', r.text) is not None)
dw=php('$d=get_posts(array("post_type"=>"attachment","post_status"=>"inherit","numberposts"=>1,"bdaci_pa_all"=>true,"suppress_filters"=>true)); echo $d ? BDACI_PA_Portal::doc_when($d[0]) : "none";').strip()
check('member document lists show upload time', re.search(r'^[A-Z][a-z]{2} \d{1,2}, \d{4} · Uploaded (\d{1,2}:\d{2} [AP]M|[A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M)$', dw) is not None, dw)
h=sb.get(P+'/'); check('home recent documents show posted time', re.search(r'Posted [A-Z][a-z]{2} \d{1,2}, \d{1,2}:\d{2} [AP]M', h.text) is not None)
w=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-docs'); check('wp-admin upload form also has search and today', 'class="sec-q"' in w.text and f'value="{today}"' in w.text)

# ======================= v2.9 rename and move documents =======================
# upload a fresh document to rename/move
r=s4.get(P+'/?manage=docs'); n=fnonce(r.text,'bdaci_pa_doc_upload')
r=s4.post(AP,data={'action':'bdaci_pa_doc_upload','_wpnonce':n,'_bdaci_front':'1','title':'Rename me','doc_date':today,'sections[]':['bylaws'],'sections_sent':'1','who[]':['board']},files=[('bdaci_file[]',('orig-name.pdf',b'%PDF-1.4\n%rename test\n','application/pdf'))])
did=php('$d=get_posts(array("post_type"=>"attachment","post_status"=>"inherit","numberposts"=>1,"orderby"=>"ID","order"=>"DESC","bdaci_pa_all"=>true,"suppress_filters"=>true)); echo $d[0]->ID;').strip()
fname=lambda: php(f'echo basename(get_attached_file({did}));').strip()
check('uploaded test document', fname().startswith('orig-name'), fname())
r=s4.get(P+'/?manage=docs')
check('edit form offers file name and move', f'id="bdaci-fn-{did}"' in r.text and 'Section (move it here)' in r.text and 'Edit, rename or move' in r.text)
def doc_edit(data):
    h=s4.get(P+'/?manage=docs').text; n=fnonce(h,'bdaci_pa_doc_edit') if False else fnonce_with(h,'bdaci_pa_doc_edit',f'name="id" value="{did}"')
    base={'action':'bdaci_pa_doc_edit','_wpnonce':n,'_bdaci_front':'1','id':did,'do':'save','title':'Rename me','doc_date':today,'sections_sent':'1','who[]':['board']}
    base.update(data); return s4.post(AP,data=base)
r=doc_edit({'file_name':'Board Agenda Dec 2026','sections[]':['bylaws']})
check('rename file keeps extension', fname()=='Board-Agenda-Dec-2026.pdf' and 'Renamed to Board-Agenda-Dec-2026.pdf' in r.text, fname())
g=sb.get(B+f'/?bdaci_doc={did}'); check('download uses new file name, link unchanged', g.status_code==200 and 'Board-Agenda-Dec-2026.pdf' in g.headers.get('Content-Disposition',''), g.headers.get('Content-Disposition'))
r=doc_edit({'file_name':'../../evil.php','sections[]':['bylaws']})
check('rename cannot escape folder or change type', fname().endswith('.pdf') and '/' not in fname() and os.path.dirname(php(f'echo get_attached_file({did});').strip()).find('bdaci-portal-private-')!=-1, fname())
r=doc_edit({'file_name':'','sections[]':['minutes','policies']})
check('move to other sections', 'Moved to' in r.text and php(f'echo implode(",",BDACI_PA_Sections::doc_sections({did}));').strip() in ('minutes,policies','policies,minutes'))
r=s4.get(P+'/?manage=docs'); check('rename and move history shown with time', re.search(r'Last moved [A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M', r.text) is not None and 'Moved <span class="muted">Bylaws</span>' in r.text and 'Renamed <span class="muted">orig-name' in r.text)
check('moves logged in activity log', 'document_moved' in php('global $wpdb; echo implode(",", $wpdb->get_col("SELECT event FROM {$wpdb->prefix}bdaci_pa_log ORDER BY id DESC LIMIT 10"));'))
h=s4.get(P+'/?manage=docs').text; n=fnonce_with(h,'bdaci_pa_doc_edit',f'name="id" value="{did}"'); s4.post(AP,data={'action':'bdaci_pa_doc_edit','_wpnonce':n,'_bdaci_front':'1','id':did,'do':'delete'})

# ======================= v2.9.1 hardening found by break testing =======================
r=s4.get(P+'/?manage=qa&group=sign'); rid=re.search(r'name="round" value="(\d+)"',r.text).group(1); n=fnonce_with(r.text,'bdaci_pa_qa_mark','value="SIGN-07"')
check('QA forms post to an address that names the action', 'admin-post.php?action=bdaci_pa_qa_mark' in r.text.replace('&#038;','&').replace('&amp;','&'))
s4.post(AP,data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-07','round':rid,'status':'fail','note':'=HYPERLINK("http://evil.example","x")','group':'sign'})
r=s4.get(P+'/?manage=qa'); csvu=re.search(r'href="([^"]*bdaci_pa_qa_csv[^"]*)"',r.text).group(1).replace('&amp;','&').replace('&#038;','&')
c=s4.get(csvu); check('QA CSV neutralises spreadsheet formulas', "'=HYPERLINK" in c.text and '",=HYPERLINK' not in c.text and '"=HYPERLINK' not in c.text)
big=tiny_png()+b'\x00'*(9*1024*1024)
r=s4.post(AP+'?action=bdaci_pa_qa_mark',headers={'Referer':P+'/?manage=qa&group=sign'},data={'action':'bdaci_pa_qa_mark','_wpnonce':n,'_bdaci_front':'1','scenario':'SIGN-07','round':rid,'status':'note','group':'sign'},files=[('shots[]',('huge.png',big,'image/png'))])
check('too-large upload explains itself instead of a blank page', 'too large to send together' in r.text, r.url)

# ======================= v2.10 updates from GitHub =======================
BN='bdaci-portal-access/bdaci-portal-access.php'
def ghlog(): 
    p=WP+'/wp-content/gh-req.log'; return open(p).read() if os.path.exists(p) else ''
def reset_upd(fake):
    php('delete_site_transient("bdaci_pa_update_info"); delete_site_transient("update_plugins"); delete_site_option("bdaci_pa_update_error");' + (f'update_option("test_fake_gh", {fake});' if fake else 'delete_option("test_fake_gh");'))
    if os.path.exists(WP+'/wp-content/gh-req.log'): os.remove(WP+'/wp-content/gh-req.log')
cur=php('echo BDACI_PA_VERSION;').strip()
check('default update source is the BDACI repo, auto-update on', php('echo BDACI_PA_Updater::repo(), "|", (int) BDACI_PA_Settings::get("update_auto");').strip()=='ericmdizon/bdaci-portal|1')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-settings')
check('settings has Updates section', 'id="bdaci-updates"' in r.text and 'GitHub access key' in r.text and 'Check for updates now' in r.text and 'Installed version: '+cur in r.text)
n=fnonce(r.text,'bdaci_pa_settings')
def save_settings(extra):
    h=s4.get(B+"/wp-admin/admin.php?page=bdaci-portal-settings").text; d={}
    for k,v in json.loads(php('echo wp_json_encode(BDACI_PA_Settings::all());')).items():
        if 'secret' in k or k=='update_token' or isinstance(v,(list,dict)): continue
        if k.startswith(('mfa_','board_','allow_','ms_enabled','google_enabled','remove_','portal_layout','portal_fonts','portal_keep','update_auto')):
            if int(v or 0): d[k]='1'
        else: d[k]=str(v)
    d.update({'action':'bdaci_pa_settings','_wpnonce':fnonce(h,'bdaci_pa_settings')}); d.update(extra)
    d={k:v for k,v in d.items() if v is not None}
    return s4.post(B+'/wp-admin/admin-post.php',data=d)
save_settings({'update_repo':'https://github.com/ericmdizon/bdaci-portal.git','update_token':'ghp_testkey123'})
check('repo URL is tidied to owner/name and key stored', php('echo BDACI_PA_Updater::repo(), "|", BDACI_PA_Updater::token();').strip()=='ericmdizon/bdaci-portal|ghp_testkey123')
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-settings'); check('access key never shown back', 'ghp_testkey123' not in r.text and 'Saved — leave blank to keep' in r.text)
save_settings({'update_repo':'bad repo; rm -rf'}); check('invalid repo rejected', php('echo "[",BDACI_PA_Updater::repo(),"]";').strip()=='[]')
save_settings({'update_repo':'ericmdizon/bdaci-portal'}); check('blank key field keeps stored key', php('echo BDACI_PA_Updater::token();').strip()=='ghp_testkey123')

reset_upd('array("code"=>200,"version"=>"2.99.0")')
r=s4.get(B+'/wp-admin/plugins.php')
check('Plugins screen shows Check for updates link', 'action=bdaci_pa_check_updates' in r.text and 'Check for updates</a>' in r.text)
lk=re.search(r'href="([^"]*action=bdaci_pa_check_updates[^"]*)"', r.text).group(1).replace('&amp;','&').replace('&#038;','&')
r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'})
check('Check for updates finds newer release', 'BDACI Portal Access 2.99.0 is available' in r.text and r.url.endswith('plugins.php'), r.url)
check('update appears on the Plugins list', 'There is a new version of BDACI Portal Access available' in r.text and 'update now' in r.text.lower(), re.findall(r'new version[^<]{0,80}', r.text)[:2])
check('GitHub asked with the access key', 'Bearer ghp_testkey123' in ghlog() and '/releases?per_page=20' in ghlog())
check('drafts, pre-releases, assetless and non-version tags ignored', php('$i=BDACI_PA_Updater::latest(); echo $i["version"];').strip()=='2.99.0')
cnt=ghlog().count('/releases?'); s4.get(B+'/wp-admin/plugins.php'); s4.get(B+'/wp-admin/'); check('release list is cached between page loads', ghlog().count('/releases?')==cnt)
r=s4.get(B+'/wp-admin/plugin-install.php?tab=plugin-information&plugin=bdaci-portal-access&section=changelog')
check('View details shows release notes and changelog', "What&#039;s new in 2.99.0" in r.text and 'Test release' in r.text and '<script>x</script>' not in r.text and '= '+cur+' =' not in r.text and cur in r.text, r.status_code)
check('auto-update filter on when setting on', php('echo (int) apply_filters("auto_update_plugin", false, (object) array("plugin"=>"'+BN+'"));').strip()=='1')
check('auto-update leaves other plugins alone', php('echo (int) apply_filters("auto_update_plugin", false, (object) array("plugin"=>"hello.php"));').strip()=='0')
save_settings({'update_auto':None}); check('auto-update off when unticked', php('echo (int) apply_filters("auto_update_plugin", false, (object) array("plugin"=>"'+BN+'"));').strip()=='0')
save_settings({'update_auto':'1'})
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-settings'); check('settings shows the available version and last check time', 'Version 2.99.0 is available' in r.text and re.search(r'Last checked [A-Z][a-z]{2} \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M', r.text) is not None)
r=sb.get(lk, allow_redirects=False); check('board member cannot trigger update check', r.status_code in (403,302) and 'is available' not in r.text, r.status_code)
r=s4.get(B+'/wp-admin/admin-post.php?action=bdaci_pa_check_updates&_wpnonce=bad'); check('check needs a valid nonce', 'is available' not in r.text and r.status_code in (403,200))

reset_upd('array("code"=>401,"version"=>"2.99.0")')
r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'}); check('bad access key explained', 'GitHub refused the access key' in r.text)
r=s4.get(B+'/wp-admin/admin.php?page=bdaci-portal-settings'); check('error shown in Settings › Updates', 'GitHub refused the access key' in r.text)
cnt=ghlog().count('/releases?'); s4.get(B+'/wp-admin/plugins.php'); s4.get(B+'/wp-admin/plugins.php'); check('failed check not retried on every page load', ghlog().count('/releases?')==cnt, ghlog().count('/releases?')-cnt)
reset_upd('array("code"=>404,"version"=>"2.99.0")'); r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'}); check('missing repo explained', 'could not find the repository' in r.text)
reset_upd(None); r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'}); check('GitHub offline does not break Plugins screen', r.status_code==200 and 'Plugins' in r.text and 'offline' in r.text)
reset_upd('array("code"=>200,"version"=>"'+cur+'")'); r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'}); check('same version reports up to date', 'is up to date ('+cur+')' in r.text and 'There is a new version of BDACI Portal Access' not in r.text)
reset_upd('array("code"=>200,"version"=>"1.0.0")'); r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'}); check('older release never offered', 'is up to date' in r.text)

# Real "update now" from the Plugins screen, only on a copy where the plugin is a real folder (not the dev symlink).
pdir=WP+'/wp-content/plugins/bdaci-portal-access'
if not os.path.islink(pdir):
    import zipfile
    with zipfile.ZipFile(WP+'/wp-content/fake-update.zip','w') as z:
        for root,_,files in os.walk(pdir):
            for f in files:
                full=os.path.join(root,f); arc=os.path.relpath(full, os.path.dirname(pdir)); data=open(full,'rb').read()
                if f=='bdaci-portal-access.php': data=data.replace(cur.encode(),b'2.99.0')
                z.writestr(arc,data)
    reset_upd('array("code"=>200,"version"=>"2.99.0")')
    r=s4.get(lk, headers={'Referer':B+'/wp-admin/plugins.php'})
    m=re.search(r'href="([^"]*update\.php\?action=upgrade-plugin[^"]*bdaci-portal-access[^"]*)"', r.text)
    check('update now link present', m is not None)
    u=m.group(1).replace('&amp;','&').replace('&#038;','&'); u=u if u.startswith('http') else B+'/wp-admin/'+u
    r=s4.get(u, timeout=120)
    check('update installs from GitHub release', 'Plugin updated successfully' in r.text, re.sub(r'<[^>]+>',' ',r.text)[-600:])
    m=re.search(r'src="([^"]*update\.php\?action=activate-plugin[^"]*)"', r.text)
    if m:  # WordPress reactivates the plugin in a small frame on the result page; a browser loads it, so do the same.
        u=m.group(1).replace('&amp;','&').replace('&#038;','&'); s4.get(u if u.startswith('http') else B+'/wp-admin/'+u)
    check('download followed the signed link without sending the key', ['https://objects.githubusercontent.com/fake/bdaci-portal-access.zip?sig=abc',''] in [json.loads(l) for l in ghlog().splitlines()], ghlog()[-400:])
    check('new version active and portal still works', php('echo BDACI_PA_VERSION, "|", (int) is_plugin_active("'+BN+'");').strip()=='2.99.0|1' and s.get(B+'/portal-login/').status_code==200)
    check('update logged', 'plugin_updated' in php('global $wpdb; echo implode(",", $wpdb->get_col("SELECT event FROM {$wpdb->prefix}bdaci_pa_log ORDER BY id DESC LIMIT 5"));'))
    check('automatic updater would install it', php('require_once ABSPATH."wp-admin/includes/class-wp-upgrader.php"; $u=new WP_Automatic_Updater(); echo (int) apply_filters("auto_update_plugin", false, (object) array("plugin"=>"'+BN+'"));').strip()=='1')
else:
    print('SKIP real update install (plugin folder is a symlink to the source)')
reset_upd(None)

print(f'\n{ok} passed, {bad} failed')
