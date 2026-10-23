#!/usr/bin/env python3
"""Every message a run can store must have a line in i18n/en_US.csv and i18n/de_DE.csv.

Runs happen in cron and store their messages in English; the admin shows them in the admin's language by matching
each sentence against the phrases of i18n/en_US.csv (Model/I18n/StoredText.php). This check collects every
sentence-like text in Model/ (string literals, also joined with "."; a joined value becomes %s) and lists those
without a line in both files, or whose German line has another number of values.

Texts that are no messages (SQL, XML, column aliases, developer errors) are listed in .github/ci/not-messages.txt.
"""
import csv
import glob
import os
import re
import sys

ROOT = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), '..', '..'))
SENTENCE = re.compile(r'[A-Za-z]{2,}[,.;:]? +(?:\S+ +)?[a-z]{2,}')
# Short formats such as "Picture %s: %s" or "row %d" (a word, then a value).
SHORT = re.compile(r'^[A-Za-z][A-Za-z ]*[a-z]{2,},? %[sd](?:[:.)]| \(|$)')
PLACEHOLDER = re.compile(r"%(?:%|[1-9]|[-+ 0',]*\d*(?:\.\d+)?[sdfFgGu])")
VALUE = '\x00'


def literal(text, start):
    """The PHP string literal starting at text[start] (a quote): its value and the position after it."""
    quote = text[start]
    i = start + 1
    out = []
    while i < len(text):
        c = text[i]
        if c == '\\' and i + 1 < len(text):
            n = text[i + 1]
            if quote == "'":
                out.append(n if n in "'\\" else c + n)
            else:
                out.append({'n': '\n', 't': '\t', '"': '"', '\\': '\\', '$': '$', 'r': '\r'}.get(n, c + n))
            i += 2
            continue
        if c == quote:
            return ''.join(out), i + 1
        if quote == '"' and c == '$' and i + 1 < len(text) and (text[i + 1].isalpha() or text[i + 1] in '_{'):
            out.append(VALUE)
        out.append(c)
        i += 1
    return ''.join(out), i


def expressions(text):
    """Literal expressions of a PHP file: literals joined with "." (a non-literal part becomes VALUE)."""
    i = 0
    tokens = []  # ('s', value) | ('.',) | ('x',) other code
    while i < len(text):
        c = text[i]
        if text.startswith('//', i) or c == '#' and not text.startswith('#[', i):
            j = text.find('\n', i)
            i = len(text) if j < 0 else j
        elif text.startswith('/*', i):
            j = text.find('*/', i + 2)
            i = len(text) if j < 0 else j + 2
        elif c in '\'"':
            value, i = literal(text, i)
            tokens.append(('s', value))
        elif c == '.' and not (i + 1 < len(text) and text[i + 1] in '.=0123456789'):
            tokens.append(('.',))
            i += 1
        elif c.isspace():
            i += 1
        else:
            if not tokens or tokens[-1][0] != 'x':
                tokens.append(('x',))
            i += 1
    result = []
    k = 0
    while k < len(tokens):
        if tokens[k][0] != 's':
            k += 1
            continue
        parts = [VALUE] if k > 0 and tokens[k - 1][0] == '.' else []
        while True:
            parts.append(tokens[k][1] if tokens[k][0] == 's' else VALUE)
            if k + 2 < len(tokens) and tokens[k + 1][0] == '.' and tokens[k + 2][0] in ('s', 'x'):
                k += 2
                continue
            k += 1
            break
        result.append(''.join(parts))
    return result


def phrases(path):
    found = set()
    for value in expressions(open(path, encoding='utf-8').read()):
        for line in value.split('\n'):
            line = re.sub(VALUE + '+', '%s', line).strip()
            if line in ('', '%s') or not (SENTENCE.search(line) or SHORT.search(line)):
                continue
            found.add(line)
    return found


def values(phrase):
    """How many values an English phrase takes (sprintf's %s, %d … or Magento's %1 …)."""
    return len([p for p in PLACEHOLDER.findall(phrase) if p != '%%'])


def slots(translation):
    """How many values a translation takes: always %1, %2 … (a lone % is a percent sign)."""
    return len(set(re.findall(r'%[1-9]', translation)))


def main():
    skip = set()
    skip_file = os.path.join(ROOT, '.github', 'ci', 'not-messages.txt')
    for line in open(skip_file, encoding='utf-8'):
        line = line.rstrip('\n')
        if line and not line.startswith('#'):
            skip.add(line)
    wanted = {}
    for path in sorted(glob.glob(os.path.join(ROOT, 'Model', '**', '*.php'), recursive=True)):
        for phrase in phrases(path):
            if phrase not in skip and not phrase.startswith(('/', '<')):
                wanted.setdefault(phrase, os.path.relpath(path, ROOT))
    problems = 0
    languages = {}
    for language in ('en_US', 'de_DE'):
        rows = csv.reader(open(os.path.join(ROOT, 'i18n', language + '.csv'), encoding='utf-8'))
        languages[language] = {row[0]: row[1] for row in rows if len(row) > 1}
    for phrase, path in sorted(wanted.items(), key=lambda item: (item[1], item[0])):
        for language, known in languages.items():
            if phrase not in known:
                problems += 1
                print(f'{language}: missing "{phrase}" ({path})')
            elif language != 'en_US' and slots(known[phrase]) != values(phrase):
                problems += 1
                print(f'{language}: "{phrase}" has {values(phrase)} value(s), the translation {slots(known[phrase])}')
    unused = sorted(skip - set().union(*(phrases(p) for p in glob.glob(os.path.join(ROOT, 'Model', '**', '*.php'),
                                                                         recursive=True))))
    for phrase in unused:
        problems += 1
        print(f'not-messages.txt: "{phrase}" is no longer in the code')
    print(f'{len(wanted)} run messages, {problems} problem(s)')
    sys.exit(1 if problems else 0)


main()
