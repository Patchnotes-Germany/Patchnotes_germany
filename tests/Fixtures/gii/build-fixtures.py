"""Build small golden-test fixtures from the downloaded GII laws.

Keeps the document header plus a handful of representative norms (lists, tables, footnotes,
repealed norms, Anlagen) so the fixtures stay a few kilobytes while covering the DTD.
Law texts are amtliche Werke (§ 5 UrhG) and therefore free to redistribute.
"""
import glob, os, re
import xml.etree.ElementTree as ET

SRC = '/private/tmp/claude-501/-Users-nikitos-sites-Patchnotes/59007200-402f-480e-bb18-7df3b81111f9/scratchpad/gii/laws'
DST = '/Users/nikitos/sites/Patchnotes/tests/Fixtures/gii'
MAX_ROWS = 3

def interesting(norms):
    """Pick a representative subset, preserving document order."""
    picked, reasons = [], {}
    def take(n, why):
        if n not in picked and len(picked) < 9:
            picked.append(n); reasons.setdefault(id(n), why)
    headers = [n for n in norms if n.find('metadaten/gliederungseinheit') is not None]
    for n in headers[:2]: take(n, 'structure heading')
    for n in norms:
        if n.find('.//DL') is not None: take(n, 'list'); break
    for n in norms:
        if n.find('.//DL//DL') is not None: take(n, 'nested list'); break
    for n in norms:
        if n.find('.//table') is not None and (n.findtext('metadaten/enbez') or '') != 'Inhaltsübersicht':
            take(n, 'table'); break
    for n in norms:
        if 'weggefallen' in (n.findtext('metadaten/titel') or '').lower(): take(n, 'repealed'); break
    for n in norms:
        if n.find('.//Footnote') is not None: take(n, 'footnotes'); break
    for n in norms:
        if (n.findtext('metadaten/enbez') or '').startswith(('Anlage', 'Anhang')): take(n, 'annex'); break
    for n in norms:
        e = n.findtext('metadaten/enbez') or ''
        if e.startswith('§') or e.startswith('Art'): take(n, 'plain norm')
        if len(picked) >= 8: break
    return sorted(picked, key=lambda n: norms.index(n))

def trim_tables(norm):
    for table in norm.iter('table'):
        for body in list(table.iter('tbody')):
            rows = list(body.findall('row'))
            for row in rows[MAX_ROWS:]:
                body.remove(row)
    return norm

os.makedirs(DST, exist_ok=True)
report = []
for path in sorted(glob.glob(SRC + '/*/*.xml')):
    slug = os.path.basename(os.path.dirname(path))
    root = ET.parse(path).getroot()
    norms = root.findall('norm')
    if not norms:
        continue
    out = ET.Element('dokumente', root.attrib)
    out.append(norms[0])
    for n in interesting(norms[1:]):
        out.append(trim_tables(n))
    ET.indent(out, space='')
    xml = ET.tostring(out, encoding='unicode')
    xml = '<?xml version="1.0" encoding="UTF-8" ?>\n' + xml + '\n'
    target = f'{DST}/{slug}.xml'
    open(target, 'w', encoding='utf-8').write(xml)
    report.append((slug, len(out.findall('norm')), len(xml)))

print(f'{"slug":22} norms  bytes')
for slug, n, size in report:
    print(f'{slug:22} {n:5}  {size}')
print('total bytes:', sum(r[2] for r in report), 'files:', len(report))
