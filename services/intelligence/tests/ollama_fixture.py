"""Private HTTP protocol fixture. Deliberately does NOT run a model or claim verification."""
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

calls = 0
class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass
    def reply(self, data, status=200):
        payload=json.dumps(data).encode()
        self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
    def do_GET(self):
        if self.path=='/fixture/stats':
            return self.reply({'calls':calls})
        if self.path=='/api/tags':
            return self.reply({'models':[{'name':'fixture-local','model':'fixture-local'}]})
        self.reply({'error':'fixture endpoint only'},404)
    def do_POST(self):
        global calls
        size=int(self.headers.get('Content-Length','0'))
        if size>200000:
            return self.reply({'error':'limit'},413)
        data=json.loads(self.rfile.read(size))
        if data.get('model')!='fixture-local':
            return self.reply({'error':'unknown fixture model'},404)
        if self.path=='/api/show':
            return self.reply({'capabilities':['completion'],'model_info':{'fixture.context_length':8192}})
        if self.path=='/api/chat':
            calls+=1
            frames=[{'model':'fixture-local','message':{'content':'SYNTHETIC LOCAL PROVIDER FIXTURE: no model inference or verification ran.'},'done':False},{'model':'fixture-local','done':True,'done_reason':'stop','prompt_eval_count':100,'eval_count':12}]
            payload=''.join(json.dumps(f)+'\n' for f in frames).encode()
            self.send_response(200);self.send_header('Content-Type','application/x-ndjson');self.send_header('Content-Length',str(len(payload)));self.end_headers();self.wfile.write(payload)
            return
        self.reply({'error':'fixture endpoint only'},404)

ThreadingHTTPServer(('0.0.0.0',11434),Handler).serve_forever()
