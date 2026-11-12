"""Read-only residual-sensitive-data scanner for sanitized diagnostics.

No record values leave MAIN. Findings contain only field paths and risk codes.
Conservative blocking gate: not a proof that arbitrary secrets cannot exist.
"""
import re
from urllib.parse import urlsplit

HIGH_RISK = (
    ("authorization", re.compile(r"(?i)\b(?:authorization|set-cookie|x-api-key)\s*[:=]")),
    ("token_shape", re.compile(r"(?i)\b(?:github_pat_|gh[pousr]_|sk-[a-z0-9_-]{12,}|bearer\s+\S{8,})")),
    ("private_key", re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----")),
    ("email", re.compile(r"(?i)\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b")),
    ("local_home", re.compile(r"(?i)(?:[a-z]:[\\/](?:users|utilisateurs)[\\/]|/(?:home|users)/)[^\\/\s]+")),
)
def scan_record(record):
    findings=[]
    def check(value, path):
        if isinstance(value,dict):
            for key,item in value.items():
                check(item,path+"."+str(key))
        elif isinstance(value,list):
            for idx,item in enumerate(value):
                check(item,path+"["+str(idx)+"]")
        elif isinstance(value,str) and not value.startswith("[REDACTED"):
            for name,pattern in HIGH_RISK:
                if pattern.search(value):
                    findings.append({"field":path,"reason":name})
            if "://" in value:
                for token in re.findall(r"https?://[^\s\x22\x27<>]+",value):
                    try:
                        parsed=urlsplit(token)
                        if parsed.username or parsed.password or parsed.query or parsed.fragment:
                            findings.append({"field":path,"reason":"unsafe_url"})
                    except ValueError:
                        findings.append({"field":path,"reason":"malformed_url"})
    check(record.get("fields",{}),"$")
    return findings
