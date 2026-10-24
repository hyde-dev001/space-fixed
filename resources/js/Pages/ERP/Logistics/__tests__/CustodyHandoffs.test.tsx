import React from 'react';
import { describe,it,expect,vi } from 'vitest';
import { fireEvent,render,screen,waitFor } from '@testing-library/react';
import CustodyHandoffs from '../components/CustodyHandoffs';
const mocks=vi.hoisted(()=>({release:vi.fn().mockRejectedValue({isAxiosError:true,response:{data:{errors:{photo:['Choose a valid JPG, PNG or WEBP image.']}}}})}));
vi.mock('@inertiajs/react',()=>({router:{reload:vi.fn()}}));
vi.mock('@/services/logisticsApi',()=>({logisticsApi:{releaseCustody:mocks.release}}));
it('identifies the parcel and shows the actual image error on its card',async()=>{
 render(<CustodyHandoffs handoffs={[{id:1,shipment_leg_id:2,delivery_reference:'Delivery #2',source_reference:'Order ORD-22',details_url:'/erp/logistics/deliveries?search=2',from_label:'Rider A',to_label:'Rider B',status:'requested',can_release:true,can_receive:false}]} />);
 expect(screen.getByText('Order ORD-22')).toBeInTheDocument();
 fireEvent.change(screen.getByLabelText('Handoff photo'),{target:{files:[new File(['bad'],'bad.png',{type:'image/png'})]}});
 fireEvent.click(screen.getByRole('button',{name:'Confirm release'}));
 await waitFor(()=>expect(mocks.release).toHaveBeenCalled());
 expect(await screen.findByRole('alert')).toHaveTextContent('Choose a valid JPG');
});
