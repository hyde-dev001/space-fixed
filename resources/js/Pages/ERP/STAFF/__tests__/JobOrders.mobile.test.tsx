import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
  expect(within(queue).getByRole('button',{name:'Review return'})).toBeEnabled();
 });
});


it('uses the normal Staff return action without incident buttons or handling messages', async () => {
 const parcel = { incident_id: 7, revision: 3, source_type: 'order', source_id: 1, shipment_id: 9, delivery_id: 10,
   operational_status: 'under_review', received: true, inspected: true, can_inspect: true, staff_review_required: true,
   expected_items: [{ reference: 'order-item:11', label: 'Returned shoe', expected_quantity: 1 }],
   actions: [{ action: 'inventory_disposition', label: 'Complete inspected item handling', allowed: true },
     { action: 'refund_review', label: 'Submit refund eligibility review', allowed: true }], transfers: [], obligations: [], source_outcome: {} };
 vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => [{ id: 1, order_number: 'RIDER-RETURN',
   customer_name: 'Buyer', status: 'shipped', items: [], total_amount: 100, grand_total: 100, parcel_returns: [parcel] }] }));
 render(<JobOrders />);
 fireEvent.click(await screen.findByRole('button', { name: /All Orders/ }));
 const queue = screen.getByRole('region', { name: 'Compact job orders' });
 const review = await within(queue).findByRole('button', { name: 'Review return' });
 expect(within(queue).queryByText('Returned items inspected')).not.toBeInTheDocument();
 expect(screen.queryByText('Complete inspected item handling')).not.toBeInTheDocument();
 expect(screen.queryByText('Submit refund eligibility review')).not.toBeInTheDocument();
 expect(screen.queryByText('Physical return handled; linked work remains')).not.toBeInTheDocument();
 fireEvent.click(review);
 const Swal = (await import('sweetalert2')).default;
 expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({ title: 'Review return' }));
});


it('submits Rider receipt and item review once through the normal Staff endpoint', async () => {
 const Swal = (await import('sweetalert2')).default;
 const photo = new File(['actual parcel'], 'return.jpg', { type: 'image/jpeg' });
 vi.mocked(Swal.fire).mockImplementation(async options => {
   if (typeof options !== 'object' || options.title !== 'Review return') return { isConfirmed: false, isDenied: false, isDismissed: true };
   const form = document.createElement('div'); form.innerHTML = String(options.html); document.body.append(form);
   (form.querySelector('#return-review-notes') as HTMLTextAreaElement).value = 'One real parcel received and reviewed';
   (form.querySelector('#return-qty-0') as HTMLInputElement).value = '1';
   (form.querySelector('#return-disp-0') as HTMLSelectElement).value = 'resellable';
   Object.defineProperty(form.querySelector('#return-review-photo'), 'files', { value: [photo] });
   const value = await options.preConfirm?.(undefined);
   form.remove();
   return { isConfirmed: true, isDenied: false, isDismissed: false, value };
 });
 let reviewed = false;
 const fetcher = vi.fn(async (url: string, options?: RequestInit) => {
   if (url === '/api/csrf-token') return { ok: true, json: async () => ({ csrf_token: 'csrf-test' }) };
   if (options?.method === 'POST') { reviewed = true; return { ok: true, json: async () => ({ success: true, message: 'Return review saved.' }) }; }
   return { ok: true, json: async () => [{ id: 1, order_number: 'RECEIVED-1', status: 'shipped', items: [], total_amount: 100,
     parcel_returns: reviewed ? [] : [{ incident_id: 7, revision: 3, staff_review_required: true, source_type: 'order',
       expected_items: [{ reference: 'order-item:11', label: 'Actual shoe', expected_quantity: 1 }], transfers: [] }] }] };
 });
 vi.stubGlobal('fetch', fetcher);
 render(<JobOrders />);
 fireEvent.click(await screen.findByRole('button', { name: /All Orders/ }));
 const queue = screen.getByRole('region', { name: 'Compact job orders' });
 fireEvent.click(await within(queue).findByRole('button', { name: 'Review return' }));
 await waitFor(() => expect(fetcher.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(1));
 const posted = fetcher.mock.calls.filter(([, options]) => options?.method === 'POST');
 expect(posted).toHaveLength(1);
 expect(posted[0][0]).toBe('/api/staff/orders/1/confirm-return-received');
 const payload = posted[0][1]?.body as FormData;
 expect(payload.get('incident_id')).toBe('7');
 expect(payload.get('expected_revision')).toBe('3');
 expect(payload.get('line_dispositions[0][order_item_id]')).toBe('11');
 expect(payload.get('line_dispositions[0][approved_qty]')).toBe('1');
 expect(payload.get('photo')).toBe(photo);
});
