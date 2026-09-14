const i=(s,r)=>s?s.shop_owner?!0:(s.permissions||[]).includes(r):!1,o=(s,r)=>{if(!s)return!1;if(s.shop_owner)return!0;const e=s.permissions||[];return r.some(n=>e.includes(n))};export{i as a,o as h};
