import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import JobOrders from '../JobOrders';
vi.mock('@inertiajs/react',()=>({Head:()=>null,usePage:()=>({props:{auth:{user:{role:'STAFF'},permissions:['access-staff-job-orders']}}})}));
vi.mock('@/layout/AppLayout_ERP',()=>({default:({children}:React.PropsWithChildren)=><main>{children}</main>}));
vi.mock('@/components/orders/RetailWarrantyPanel',()=>({default:()=>null}));
vi.mock('sweetalert2',()=>({default:{fire:vi.fn().mockResolvedValue({isConfirmed:false})}}));
afterEach(()=>{cleanup();vi.unstubAllGlobals()});
describe('compact staff inspection queue',()=>{
 it('keeps the order, item, quantity and inspection action together',async()=>{
  vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,json:async()=>[{id:1,order_number:'ORD-RETURN',customer_name:'Synthetic customer',status:'shipped',product_name:'Returned shoe',quantity:2,items:[],total_amount:500,grand_total:500,latest_refund:{id:2,status:'requested',reason_code:'delivery_attempts_exhausted',shop_owner_status:'approved',finance_status:'pending',return_status:'in_transit',return_source:'staff',staff_return_carrier:'Shop-owned logistics',flow_type:'request_approval'}}]}));
  render(<JobOrders/>);
  await screen.findByRole('button',{name:/Refund \(1\)/});fireEvent.click(screen.getByRole('button',{name:/Refund \(1\)/}));
  const queue=screen.getByRole('region',{name:'Compact job orders'});
  expect(within(queue).getByText('ORD-RETURN')).toBeInTheDocument();
  expect(within(queue).getByText('Synthetic customer')).toBeInTheDocument();
  expect(within(queue).getByRole('button',{name:'Inspect returned items'})).toBeEnabled();
 });
});
