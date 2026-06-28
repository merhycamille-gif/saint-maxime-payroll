# -*- coding: utf-8 -*-
import sys, os, json, glob
for _b in [r'C:\Users\user\AppData\Roaming\Python', os.path.expandvars(r'%APPDATA%\Python')]:
    for _sp in glob.glob(os.path.join(_b,'Python*','site-packages')):
        if _sp not in sys.path: sys.path.insert(0,_sp)
import openpyxl
spec=json.load(open(sys.argv[1],encoding='utf-8'))
wb=openpyxl.load_workbook(spec['template'])
ws=wb[spec['sheet']] if spec.get('sheet') else wb.active
for cell,val in spec['cells'].items():
    ws[cell]=val
wb.save(spec['out'])
sys.stdout.write('OK')
