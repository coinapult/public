import os
import sys

module_name = sys.argv[1]

module = 'payment'
basename = ['admin', 'catalog']
commondir = ['controller/{}', 'language/english/{}', 'model/{}']
specdir = {'admin': ['view/template/{}'],
           'catalog': ['view/theme/default/template/{}']}


def build(bn, dlist):
    for item in dlist:
        psplit = item.split('/')
        curname = ''
        for name in psplit:
            curname = os.path.join(curname, name)
            dpath = os.path.join(bn, curname).format(module)
            mkdir(dpath)
            if dpath.endswith(module):
                # Create blank file
                ext = 'tpl' if 'template' in curname else 'php'
                open(os.path.join(dpath, '%s.%s' % (module_name, ext)), 'w')

def mkdir(name):
    try:
        os.mkdir(name)
    except OSError, e:
        if e.errno == 17:
            print "'%s' already exists" % name
        else:
            raise

# Create module structure.
for bn in basename:
    mkdir(bn)
    build(bn, commondir)
    build(bn, specdir[bn])
