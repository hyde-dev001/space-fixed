const o=(e,t)=>{const n=Math.abs(Number(e??0)),r=Number(t??0);return!Number.isFinite(n)||!Number.isFinite(r)||r<=0?0:Math.round(n/r*1e4)/100},s=(e,t)=>`${o(e,t).toFixed(2)}%`;export{s as f};
