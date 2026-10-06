from client import *
results=[];state={}
def step(name,action,data,method='POST'):
 status,body=request(action,method,data,'sim_setup_'+name)
 ok=200<=status<300 and body.get('success') is not False
 results.append({'test':name,'status':status,'pass':ok,'message':body.get('error',body.get('message',''))})
 print(name,status,'PASS' if ok else 'FAIL',body.get('error',body.get('message','')))
 (base/'logs/setup-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
 if not ok:raise SystemExit(1)
 return body
step('profile','property-setup',{'command':'save','propertyName':'SIMULATION HOTEL','address':'Alamat Simulasi','phone':'0000000000','email':'simulation@example.invalid','taxSetupMode':'not_applicable','paymentSetupMode':'cash_only'})
for key,typ in [('room_rental','income'),('extra_service','income'),('pos_revenue','income'),('pos_refund','expense'),('pos_cogs','expense'),('pos_cogs_reversal','income'),('payroll_expense','expense'),('maintenance_expense','expense')]:
 b=step('category_'+key,'categories',{'name':'SIM '+key,'type':typ});state[key]=b['categoryId']
 step('binding_'+key,'categories-semantic-bind',{'categoryId':state[key],'systemKey':key})
step('roomtype','subcategories',{'categoryId':state['room_rental'],'subcategoryName':'SIM Deluxe'})
for number in [str(n) for n in range(101,111)]:
 step('room_'+number,'rooms',{'number':number,'type':'SIM Deluxe','price':200000,'floor':1})
for n in [1,2,3,4]:
 b=step('product_'+str(n),'pos-product-save',{'sku':'SIM-0'+str(n),'name':'SIM Product '+str(n),'costPrice':0.25,'salePrice':1,'initialStock':100,'taxKind':'extra'})
 state['product_'+str(n)]=b['product']['id']
b=step('finalize','property-setup',{'command':'finalize'});state['ready']=b.get('data',{}).get('ready')
b=step('open_shift','operations-center',{'command':'shift-open','openingCash':100000,'shiftTime':'malam','notes':'SIMULATION'})
(base/'state.json').write_text(json.dumps(state),encoding='utf8')
print('Setup completed, READY=',state['ready'])
