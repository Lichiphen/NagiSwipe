#!/usr/bin/env python3
"""
Minify a stylesheet into one line that keeps its first comment (the licence line), standard library only.

    python dev/minify_css.py FILE.css            print the minified text
    python dev/minify_css.py FILE.css --check    show the sizes

dev/build_release.py packs the release ZIP's .css files through minify(); the sources in the repository stay
as they are written. Only safe changes are made: comments go, runs of white space become one space, and the
spaces around { } ; , and the colon of a declaration go, with the last ; of a block. Strings and url(...)
are copied as they are, so are the spaces inside calc() (they are needed there) and before a descendant :hover.
"""
import re
import sys
from pathlib import Path

# Blocks whose content is more rules (not declarations).
NESTING = re.compile(r'@(?:media|supports|container|layer|document|scope|starting-style)\b', re.I)


def minify(text: str) -> str:
    head = ''
    m = re.match(r'\s*(/\*.*?\*/)', text, re.S)
    if m:
        head = re.sub(r'\s+', ' ', m.group(1)); text = text[m.end():]
    out = []          # pieces of the result
    stack = []        # 'rules' or 'decl' for each open brace
    prelude = ''      # text since the last { } or ; (to know what a { opens)
    i, n = 0, len(text)

    def last():
        return out[-1][-1] if out and out[-1] else ''

    def space():
        # One space, only between two pieces that need it.
        if out and last() not in ' {};,:' and not (out[-1].endswith('(')):
            out.append(' ')

    while i < n:
        ch = text[i]
        if text.startswith('/*', i):
            end = text.find('*/', i + 2)
            i = n if end < 0 else end + 2
            continue
        if ch in '"\'':
            j = i + 1
            while j < n and text[j] != ch:
                j += 2 if text[j] == '\\' else 1
            piece = text[i:j + 1]; out.append(piece); prelude += piece; i = j + 1
            continue
        if text.startswith('url(', i) or text.startswith('URL(', i):
            j = text.find(')', i)
            piece = text[i:j + 1]; out.append(piece); prelude += piece; i = j + 1
            continue
        if ch.isspace():
            while i < n and text[i].isspace(): i += 1
            nxt = text[i] if i < n else ''
            in_decl = bool(stack) and stack[-1] == 'decl'
            # Spaces before these never matter; before a colon only inside declarations (".a :hover" is a selector).
            if nxt in '{};,' or (nxt == ':' and in_decl) or nxt == '' or nxt == ')':
                continue
            space()
            prelude += ' '
            continue
        if ch == '{':
            while out and out[-1] == ' ': out.pop()
            stack.append('rules' if NESTING.match(prelude.strip()) else 'decl')
            out.append('{'); prelude = ''; i += 1
            continue
        if ch == '}':
            while out and out[-1] == ' ': out.pop()
            if out and out[-1] == ';': out.pop()
            if stack: stack.pop()
            out.append('}'); prelude = ''; i += 1
            continue
        if ch == ';':
            while out and out[-1] == ' ': out.pop()
            out.append(';'); prelude = ''; i += 1
            continue
        if ch == ',':
            while out and out[-1] == ' ': out.pop()
            out.append(','); prelude += ','; i += 1
            continue
        if ch == ':' and stack and stack[-1] == 'decl':
            while out and out[-1] == ' ': out.pop()
            out.append(':'); prelude += ':'; i += 1
            continue
        out.append(ch); prelude += ch; i += 1
    body = ''.join(out).strip()
    return (head + body).replace('\r', '').replace('\n', ' ') + '\n'


if __name__ == '__main__':
    src = Path(sys.argv[1]).read_text(encoding='utf-8')
    result = minify(src)
    if '--check' in sys.argv:
        print(f'{len(src.encode())} -> {len(result.encode())} bytes')
    else:
        sys.stdout.write(result)
