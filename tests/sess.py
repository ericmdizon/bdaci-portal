import requests, re, json, sys
B='http://localhost:8089'; L=B+'/portal-login/'; WP=sys.argv[2]
def nonce(html, step):
    for f in re.findall(r'<form.*?</form>', html, re.S):
        if f'value="{step}"' in f:
            return re.search(r'name="_bdaci_nonce" value="([^"]+)"', f).group(1)
def last_code(to):
    lines=[json.loads(l) for l in open(WP+'/wp-content/mail.log')]
    for l in reversed(lines):
        if l['to']==to and 'verification code' in l['subject']:
            return re.search(r'code is: (\d{6})', l['message']).group(1)
def member(email,pw):
    s=requests.Session(); r=s.get(L)
    r=s.post(L,data={'log':email,'pwd':pw,'bdaci_step':'password','_bdaci_nonce':nonce(r.text,'password')})
    s.post(L,data={'code':last_code(email),'bdaci_step':'code','_bdaci_nonce':nonce(r.text,'code')})
    return s
def admin():
    s=requests.Session(); s.get(B+'/wp-login.php')
    s.post(B+'/wp-login.php',data={'log':'admin','pwd':'AdminPass!2345','testcookie':'1'})
    return s
