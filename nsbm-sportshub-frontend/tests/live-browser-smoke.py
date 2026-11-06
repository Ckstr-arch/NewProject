#!/usr/bin/env python3
"""Five synthetic browser checks for the current JWT HTTP transport.

Start the frontend static server, then run with Python Playwright + Chromium.
These checks simulate fetch responses; they do not prove MySQL connectivity.
Real integration is tested separately by php-backend-browser.py.
"""
import argparse
from playwright.sync_api import sync_playwright
parser=argparse.ArgumentParser()
parser.add_argument('--base-url',default='http://localhost:5500')
parser.add_argument('--browser',default='/usr/bin/chromium')
args=parser.parse_args()
with sync_playwright() as p:
    browser=p.chromium.launch(executable_path=args.browser,headless=True,args=['--no-sandbox'])
    page=browser.new_page()
    page.goto(args.base_url.rstrip('/')+'/#/login')
    result=page.evaluate('''async () => {
      const {createHttpClient}=await import('/js/services/http.js');
      const checks=[],calls=[];let saved=null;
      const fake=async(url,options)=>{
        calls.push({url,options});
        if(url.endsWith('/auth/login'))return new Response(JSON.stringify({data:{access_token:'QA-token',user:{id:1}}}),{status:200});
        if(url.endsWith('/clubs/1'))return new Response(JSON.stringify({error:{code:'VALIDATION_ERROR',message:'Check fields',details:{name:'Required'}}}),{status:422});
        if(url.endsWith('/auth/logout'))return new Response(JSON.stringify({data:{logged_out:true}}),{status:200});
        return new Response(JSON.stringify({data:{items:[],page:1,limit:10,total:0,pages:0}}),{status:200});
      };
      const client=createHttpClient({baseUrl:'https://qa.test/api/v1',fetcher:fake,tokenStore:{get:()=>null,set:v=>saved=v}});
      await client.request('POST','/auth/login',{email:'a@test',password:'development'});
      if(saved!=='QA-token')throw Error('JWT not stored');checks.push('login stores bearer token');
      await client.request('GET','/clubs',undefined,{search:'a&b',page:1});
      if(calls[1].options.headers.Authorization!=='Bearer QA-token'||calls[1].options.credentials!=='omit')throw Error('Incorrect bearer/cookie policy');
      checks.push('bearer header is sent without cookies');
      if(!calls[1].url.includes('search=a%26b'))throw Error('Query escaping');checks.push('query values are encoded');
      let caught=false;try{await client.request('PUT','/clubs/1',{name:''});}catch(e){caught=e.status===422&&e.code==='VALIDATION_ERROR'&&e.errors.name==='Required';}
      if(!caught)throw Error('Nested field error unavailable');checks.push('nested 422 errors retain code and field details');
      await client.request('POST','/auth/logout');
      if(client.hasToken()||saved!==null)throw Error('Logout token not cleared');checks.push('logout clears local token');
      return checks;
    }''')
    for item in result: print('PASS',item)
    print(f'{len(result)} synthetic transport checks passed; no backend connectivity claim.')
    browser.close()
