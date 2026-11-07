"""Real HTTP/queue/ledger workflow using compose.ai-smoke.yaml's explicit protocol fixture.

No paid inference, no real Ollama inference, no shell/code execution by the application.
"""
import json
import secrets
import subprocess
import time
import uuid
from http_client import Browser

browser=Browser();browser.request('/sanctum/csrf-cookie',expected=204)
password=secrets.token_urlsafe(24)
browser.request('/api/v1/register','POST',{'name':'Optimization fixture','email':f'optimization-{secrets.token_hex(8)}@example.com','password':password,'password_confirmation':password},expected=201)
org=browser.request('/api/v1/organizations')['data'][0]['id']
project=browser.request('/api/v1/projects','POST',{'organization_id':org,'name':'Optimization HTTP fixture'},expected=201)['data']
url='/api/v1/projects/'+project['id']
def stats():
    result=subprocess.run(['docker','compose','--env-file','.env','-f','infrastructure/compose.yaml','exec','-T','ollama','python','-c',"import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:11434/fixture/stats').read().decode())"],check=True,capture_output=True,text=True)
    return json.loads(result.stdout)['calls']
try:
    providers=browser.request('/api/v1/providers')['data']
    assert len(providers)==1 and providers[0]['provider']=='ollama' and providers[0]['available'] is True
    conversation=browser.request(url+'/conversations','POST',{'title':'Local-only smoke'},expected=201)['data']
    assert conversation['provider_mode']=='ollama' and project['cloud_processing_allowed'] is False
    c='/api/v1/conversations/'+conversation['id']
    browser.request(c+'/preferences','PATCH',{'provider_mode':'ollama','preferred_model':'fixture-local','allow_fallback':False})
    key=str(uuid.uuid4());payload={'content':'Explain this synthetic fixture','idempotency_key':key}
    first=browser.request(c+'/messages','POST',payload,expected=202)['data']
    assert browser.request(c+'/messages','POST',payload,expected=202)['data']['id']==first['id']
    def finished(message_id):
        deadline=time.monotonic()+60
        while True:
            messages=browser.request(c+'/messages')['data'];reply=next(m for m in messages if m['id']==message_id)
            if reply['status']=='completed':return reply
            assert reply['status']!='failed',reply.get('error_code')
            assert time.monotonic()<deadline,'Queue timeout'
            time.sleep(1)
    reply=finished(first['id']);assert reply['provider']=='ollama' and reply['model']=='fixture-local' and 'SYNTHETIC' in reply['content']
    browser.request(c+'/messages/'+reply['id']+'/stream')
    assert 'event: completed' in browser.last_body.decode() and 'SYNTHETIC' in browser.last_body.decode()
    assert reply['context_metrics']['tokenizer']=='o200k_base'
    assert reply['context_metrics']['input_tokens']+reply['context_metrics']['output_reserved']+reply['context_metrics']['safety_reserved']<=8192
    browser.request(c+'/preferences','PATCH',{'provider_mode':'openai','preferred_model':None,'allow_fallback':False},expected=403)
    browser.request(c+'/preferences','PATCH',{'provider_mode':'auto','preferred_model':'fixture-local','allow_fallback':False})
    assert len(browser.request(c+'/messages')['data'])==2
    summary=browser.request(c+'/summary','POST',expected=202)['data'];deadline=time.monotonic()+60
    while True:
        summary=browser.request(c+'/summary')['data']
        if summary['status']=='ready':break
        assert summary['status']!='failed' and time.monotonic()<deadline
        time.sleep(1)
    assert summary['provider']=='ollama'
    compatibility={'framework':'Fixture','framework_version':'1','dependencies':{},'environment':'Synthetic test','git_commit':None}
    solution=browser.request(url+'/solutions','POST',{'error_signature':'synthetic-fixture-error','content':'SYNTHETIC ATTESTED CACHE FIXTURE: not a real verified fix.','compatibility':compatibility,'evidence':{'command':'synthetic fixture only','exit_code':0,'result':'Synthetic workflow evidence; no indexed code executed.','attestation':True}},expected=201)['data']
    before=stats()
    cached=browser.request(c+'/messages','POST',{'content':'Use the matching fixture solution','idempotency_key':str(uuid.uuid4()),'solution_context':{'error_signature':'synthetic-fixture-error','compatibility':compatibility}},expected=202)['data']
    assert finished(cached['id'])['provider']=='cache' and stats()==before
    usage=browser.request(url+'/usage');assert usage['scope']=='project'
    assert len(usage['data']['data'])==3 and any(call['cache_hit'] for call in usage['data']['data'])
    assert all(float(row['accounted_micro_usd'])==0 for row in usage['totals'])
    assert browser.request('/api/v1/organizations/'+org+'/usage')['data']
    browser.request('/api/v1/solutions/'+solution['id']+'/invalidate','POST')
    print('PASS: live session/preferences, local-only queued NDJSON/SSE contract, context budget, explicit summary, compatible private cache without another inference, zero local API charges and scoped usage. Model responses/token counts are explicit protocol fixtures, not real inference.')
finally:
    browser.request(url,'DELETE',expected=204)
    assert len(browser.request('/api/v1/usage')['data']['data'])==3, 'Project deletion erased budget history'
