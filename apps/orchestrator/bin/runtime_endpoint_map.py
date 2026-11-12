#!/usr/bin/env python3
"""Static EZS ingress/egress contract; --probe sends only allowlisted GETs."""
import argparse
import json
from pathlib import Path
from urllib.request import Request, build_opener, ProxyHandler
from urllib.error import HTTPError, URLError

ROOT = Path(__file__).resolve().parents[1]

def load(root=ROOT):
    cfg = json.loads((root / 'config/runtime.json').read_text(encoding='utf-8-sig'))
    endpoints = json.loads((root / 'docs/endpoints.v1.json').read_text(encoding='utf-8'))
    if cfg.get('schema') != 'ezs.orchestrator.config.v5':
        raise ValueError('CONFIG_SCHEMA_MISMATCH')
    if endpoints.get('schema') != 'ezs.runtime.endpoints.v1':
        raise ValueError('ENDPOINT_SCHEMA_MISMATCH')
    services = cfg['servers']
    for key in ('dev','prod','lab'):
        data=services[key]
        for k in ('public_port','backend_port'):
            port=data[k]
            if not isinstance(port,int) or not 1 <= port <= 65535:
                raise ValueError('INVALID_PORT:'+key+':'+k)
    if services['prod']['gateway_port'] == services['prod']['backend_port']:
        raise ValueError('PROD_GATEWAY_COLLISION')
    for service in endpoints['services']:
        for item in service.get('entrypoints',[]):
            if item['method'] not in ('GET','POST','PUT','PATCH','DELETE'):
                raise ValueError('INVALID_METHOD')
            if not item['path'].startswith('/'):
                raise ValueError('INVALID_ENDPOINT_PATH')
    for item in endpoints['probes']:
        if item.get('method') != 'GET' or '{' in item['path']:
            raise ValueError('UNSAFE_PROBE')
        if item['service'] not in ('lab','prod','dev'):
            raise ValueError('UNKNOWN_PROBE_SERVICE')
    return cfg,endpoints

def urls(cfg):
    servers=cfg['servers']
    out={}
    for site in ('dev','prod','lab'):
        spec=servers[site]
        out[site]={'public':'http://127.0.0.1:'+str(spec['public_port']),
                   'backend':'http://127.0.0.1:'+str(spec['backend_port'])}
        if 'gateway_port' in spec:
            out[site]['gateway']='http://127.0.0.1:'+str(spec['gateway_port'])
    return out

def probe(cfg, endpoints):
    targets=urls(cfg)
    opener=build_opener(ProxyHandler({}))
    for item in endpoints['probes']:
        service=item['service']
        role=item.get('role','public')
        url=targets[service][role] + item['path']
        try:
            req=Request(url,method='GET',headers={'Accept':'application/json,text/html'})
            with opener.open(req,timeout=3) as response:
                print('HTTP',service,role,item['path'],response.status,response.headers.get('Content-Type','unknown'))
        except HTTPError as exc:
            print('HTTP',service,role,item['path'],exc.code,exc.headers.get('Content-Type','unknown'))
        except (URLError, TimeoutError, OSError) as exc:
            print('UNREACHABLE',service,role,item['path'],type(exc).__name__)

def main():
    parser=argparse.ArgumentParser()
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true',help='Static contract; no HTTP calls')
    mode.add_argument('--probe',action='store_true',help='Opt-in allowlisted GET status only; no response bodies')
    args=parser.parse_args()
    cfg,endpoints=load()
    for name,roles in urls(cfg).items():
        print(name.upper(), ' '.join(k+'='+v for k,v in roles.items()))
    print('CONTRACT_OK_NO_MUTATION')
    if args.probe:
        probe(cfg,endpoints)

if __name__=='__main__':
    main()
