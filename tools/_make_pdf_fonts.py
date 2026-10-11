# يبني خطوط TTF ثابتة (Regular/Bold) من خطوط البرنامج woff2 المتغيّرة، متوافقة مع محرّك mPDF
# (يشيل GPOS/المتغيّرات، ويشيل lookups الـGSUB من النوع 5 شكل 3 وعلم MarkFilteringSet التي لا يدعمها mPDF)
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
import os
src = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'fonts'); dst = os.path.join(src, 'ttf'); os.makedirs(dst, exist_ok=True)

def fix_gsub(f):
    g = f['GSUB'].table; LL = g.LookupList
    bad = set()
    for i, lk in enumerate(LL.Lookup):
        for st in lk.SubTable:
            t, fmt = lk.LookupType, getattr(st, 'Format', None)
            if t == 7: t, fmt = st.ExtensionLookupType, getattr(st.ExtSubTable, 'Format', None)
            if t == 5 and fmt == 3: bad.add(i)
    keep = [i for i in range(len(LL.Lookup)) if i not in bad]; remap = {o: n for n, o in enumerate(keep)}
    LL.Lookup = [LL.Lookup[i] for i in keep]; LL.LookupCount = len(LL.Lookup)
    for fr in g.FeatureList.FeatureRecord:
        fr.Feature.LookupListIndex = [remap[x] for x in fr.Feature.LookupListIndex if x in remap]; fr.Feature.LookupCount = len(fr.Feature.LookupListIndex)
    for lk in LL.Lookup:
        lk.LookupFlag &= ~0x0010; lk.MarkFilteringSet = None
        for st in lk.SubTable:
            sub = st.ExtSubTable if lk.LookupType == 7 else st
            recs = getattr(sub, 'SubstLookupRecord', None)
            if recs:
                sub.SubstLookupRecord = [r for r in recs if r.LookupListIndex in remap]
                for r in sub.SubstLookupRecord: r.LookupListIndex = remap[r.LookupListIndex]
                sub.SubstCount = len(sub.SubstLookupRecord)
            for setname in ('ChainSubRuleSet', 'ChainSubClassSet', 'SubRuleSet', 'SubClassSet'):
                for s in (getattr(sub, setname, None) or []):
                    if s is None: continue
                    for rule in (getattr(s, 'ChainSubRule', None) or getattr(s, 'ChainSubClassRule', None) or getattr(s, 'SubRule', None) or getattr(s, 'SubClassRule', None) or []):
                        rule.SubstLookupRecord = [r for r in rule.SubstLookupRecord if r.LookupListIndex in remap]
                        for r in rule.SubstLookupRecord: r.LookupListIndex = remap[r.LookupListIndex]
                        rule.SubstCount = len(rule.SubstLookupRecord)
    return len(bad)

for woff, base in [('naskh-ar.woff2', 'NotoNaskhArabic'), ('f1.woff2', 'CairoArabic')]:
    for w, suf in [(400, '-Regular'), (700, '-Bold')]:
        f = TTFont(os.path.join(src, woff)); f.flavor = None
        axes = {a.axisTag: (a.minValue, a.defaultValue, a.maxValue) for a in f['fvar'].axes}
        loc = {t: (max(mn, min(mx, w)) if t == 'wght' else df) for t, (mn, df, mx) in axes.items()}
        inst = instancer.instantiateVariableFont(f, loc, inplace=False)
        for tbl in ('GPOS', 'STAT', 'HVAR', 'MVAR', 'avar', 'fvar', 'gvar'):
            if tbl in inst: del inst[tbl]
        n = fix_gsub(inst) if 'GSUB' in inst else 0
        if 'GDEF' in inst:
            inst['GDEF'].table.MarkGlyphSetsDef = None; inst['GDEF'].table.Version = 0x00010000
        out = os.path.join(dst, base + suf + '.ttf'); inst.save(out)
        print(out, os.path.getsize(out) // 1024, 'KB', loc, 'dropped lookups', n)
