#!/usr/bin/env python

import sqlite
import urllib2
import csv
import cgi
import simplejson
import jsontemplate
import time

log = open('log.txt', 'a')

def urldecode(query):
   d = {}
   a = query.split('&')
   for s in a:
      if s.find('='):
         k,v = map(urllib2.unquote, s.split('='))
         try:
            d[k].append(v)
         except KeyError:
            d[k] = [v]
 
   return d

def load_table(uri, cur):
   table = uri.split('/')[-1]
   table = table.split('.')[0]

   contents = urllib2.urlopen(uri)
   fields = ""
   for field in contents.readline().strip().split(','):
     fields += field
     fields += ","
   fields = fields.rstrip(',')

   cur.execute("SELECT name FROM sqlite_master WHERE type='table' \
      AND name='%s';" % (table))
   if cur.fetchone() is None:
#      cur.execute("DROP TABLE %s;" % (table))
      cur.execute(f"CREATE TABLE {table} ({fields});")
      for line in contents:
         values = line.strip()
         values = "','".join([val.strip() for val in values.split(",")])
         values = f"'{values}'"
         sql = f"INSERT INTO {table} ({fields}) VALUES ({values});"
         cur.execute(sql)
   return table

def build_structure(headings, allresults):
   results = [dict(zip(headings, result)) for result in allresults]
   results = { "query" : results }
   return results

def build_json(headings, allresults, callback):
   results = build_structure(headings, allresults)
   return_str = simplejson.dumps(results)
   if callback != None:
      return_str = f"{callback}({return_str});";
   return return_str

def load_template(templatefile):
  return "".join(urllib2.urlopen(templatefile).readlines())

def build_template(headings, allresults, template_str):
   results = build_structure(headings, allresults)
   return jsontemplate.expand(template_str, results)

def myapp(environ, start_response):
   args = cgi.parse_qs(environ['QUERY_STRING'])

   query = args['query'][0]
   uri = args['uri'][0]
   callback = args['callback'][0] if 'callback' in args else None
   label = args['label'][0] if 'label' in args else "no label"
   templatefile = args['templatefile'][0] if 'templatefile' in args else None
   con = sqlite.connect('mydatabase.db')
   cur = con.cursor()
   table_uris = uri.split(',')
   tables = [load_table(uri, cur) for uri in table_uris]
   con.commit()
   before = time.time()
   cur.execute(query)
   allresults = cur.fetchall()
   after = time.time()
   log.write("%s: query time %f\n" % (label, after - before))

   headings = [name[0] for name in cur.description]
   return_str = ""
   if templatefile is None:
      start_response('200 OK', [('Content-Type', 'text/plain')])
      before = time.time()
      return_str = build_json(headings, allresults, callback)
      after = time.time()
      log.write("%s: json-making time %f\n" % (label, after - before))
   else:
      start_response('200 OK', [('Content-Type', 'text/html')])
      before = time.time()
      template_str = load_template(templatefile)
      after = time.time()
      log.write("%s: template loading time %f\n" % (label, after - before))
      before = time.time()
      return_str = build_template(headings, allresults, template_str)
      after = time.time()
      log.write("%s: template rendering time %f\n" % (label, after - before))
   return return_str

if __name__ == '__main__':
    from fcgi import WSGIServer
    WSGIServer(myapp).run()
