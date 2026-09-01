import{j as e,H as k,L as r}from"./app-BAFiUisr.js";import{r as t}from"./vendor-apexcharts-DPaa2SoU.js";import{N as S}from"./Navigation-C0VNGn_i.js";import{u as C}from"./useScrollReveal-D_71BdKI.js";/* empty css            */import"./UserModal-CexdwQOr.js";import"./vendor-sweetalert2-BooRRB_8.js";import"./NotificationBell-Cy-cGduP.js";import"./useNotifications-QX8F_4M-.js";import"./useQuery-CRYvURgU.js";import"./useMutation-AtHQWgQn.js";import"./circle-alert-Mm6_3k1V.js";import"./createLucideIcon-DJwqyj2f.js";import"./trash-2-DuttYTXG.js";import"./clock-BgzO8gh-.js";import"./wrench-WOmH2VFA.js";import"./star-CJ0-L3gq.js";import"./ThemeToggleButton-wyfJOV8M.js";import"./resolveNotificationActionUrl-Cbpqk05R.js";import"./bell-D8NO6Ce2.js";import"./check-check-D0gUPoxf.js";import"./x-BqoLWQoM.js";const _=[{title:"Shoes",routeName:"products",image:"/images/shop/p1.jpg",alt:"SoleSpace footwear collection"},{title:"Repair",routeName:"repair",image:"/images/shop/p2.jpg",alt:"SoleSpace shoe repair service"},{title:"Services",routeName:"services",image:"/images/shop/p3.jpg",alt:"SoleSpace footwear care services"}],X=({products:h=[]})=>{const l=[{src:"/images/shop/p1.jpg",imageClass:"object-[72%_48%] sm:object-[center_48%]",overlayClass:"bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45"},{src:"/images/shop/p2.jpg",imageClass:"object-[60%_38%] sm:object-[center_38%]",overlayClass:"bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45"},{src:"/images/shop/p3.jpg",imageClass:"object-[66%_65%] sm:object-[center_65%]",overlayClass:"bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45"},{src:"/images/shop/p4.jpg",imageClass:"object-[66%_51%] sm:object-[center_51%]",overlayClass:"bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45"}],[n,x]=t.useState(0),[o,p]=t.useState(!1),f=t.useRef(null),g=t.useRef(null),c=t.useRef(null),u=t.useRef(null);C(g),t.useEffect(()=>{const s=window.setInterval(()=>{x(a=>(a+1)%l.length)},4500);return()=>window.clearInterval(s)},[l.length]),t.useEffect(()=>{const s=f.current,a=c.current,v=u.current;if(!s||!a||!v)return;const j=()=>{const d=Math.ceil(a.getBoundingClientRect().height);s.style.setProperty("--landing-footer-height",`${d}px`)};j();const m=typeof ResizeObserver>"u"?null:new ResizeObserver(j);if(m?.observe(a),typeof IntersectionObserver>"u")return p(!0),()=>{m?.disconnect(),s.style.removeProperty("--landing-footer-height")};const w=new IntersectionObserver(([d])=>p(d?.isIntersecting??!1),{threshold:0});return w.observe(v),()=>{w.disconnect(),m?.disconnect(),s.style.removeProperty("--landing-footer-height")}},[]),t.useEffect(()=>{c.current?.toggleAttribute("inert",!o)},[o]);const b="group inline-flex w-full max-w-full items-center justify-center gap-3 rounded-full px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.14em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:w-auto sm:px-10 sm:py-4 sm:text-sm sm:tracking-[0.18em]",y="landing-primary-cta border border-white bg-[#ffffff] text-[#0f172a] backdrop-blur-md shadow-[0_18px_35px_-18px_rgba(0,0,0,0.55)] hover:-translate-y-0.5 hover:border-[#f8fafc] hover:bg-[#f8fafc] hover:text-[#0f172a] hover:shadow-[0_24px_38px_-18px_rgba(0,0,0,0.55)] dark:border-[#334155] dark:bg-[#16233b] dark:text-white dark:hover:border-[#475569] dark:hover:bg-[#213257] dark:hover:text-white focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black",N="border border-white/55 bg-black/45 text-white backdrop-blur-sm shadow-[0_14px_28px_-18px_rgba(0,0,0,0.95)] hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] hover:text-white hover:shadow-[0_22px_40px_-18px_rgba(0,0,0,0.95)] focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black",i="mx-auto w-full max-w-screen-2xl px-4 sm:px-6 lg:px-12";return e.jsxs(e.Fragment,{children:[e.jsx(k,{title:"SoleSpace - Premium Footwear & Expert Repairs"}),e.jsxs("div",{ref:f,className:"landing-page relative min-h-screen overflow-x-hidden font-outfit antialiased",children:[e.jsxs("div",{ref:g,className:"landing-curtain relative z-10 bg-white",children:[e.jsx(S,{mobileMenuTriggerIcon:"hamburger",landingSidebar:!0}),e.jsxs("main",{children:[e.jsx("div",{children:e.jsxs("section",{className:"relative flex min-h-[84svh] w-full items-center overflow-hidden sm:min-h-svh",children:[e.jsxs("div",{className:"absolute inset-0 z-0",children:[l.map((s,a)=>e.jsx("img",{src:s.src,alt:`Shop background ${a+1}`,className:`absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ${s.imageClass} ${a===n?"opacity-100":"opacity-0"}`,loading:a===0?"eager":"lazy",decoding:"async"},s.src)),e.jsx("div",{className:`absolute inset-0 ${l[n]?.overlayClass??"bg-black/45"}`})]}),e.jsx("div",{className:"w-full px-4 sm:px-6 lg:px-10 flex min-h-[84svh] items-center pb-12 pt-20 sm:min-h-svh sm:py-24 lg:py-32",children:e.jsxs("div",{className:"relative z-10 w-full max-w-[24rem] text-left sm:max-w-152 lg:max-w-4xl",children:[e.jsxs("h1",{className:"hero-headline mb-4 text-[2.35rem] font-bold leading-[0.9] tracking-tight text-white/90 xsm:text-[2.75rem] sm:mb-8 sm:text-[4.4rem] md:text-7xl lg:text-8xl xl:text-9xl",children:[e.jsx("span",{className:"hero-headline-line hero-line-1 landing-hero-motion",children:"ELEVATE YOUR"}),e.jsx("span",{className:"hero-headline-line hero-line-2 landing-hero-motion",children:"SIGNATURE"}),e.jsx("span",{className:"hero-headline-line hero-line-3 landing-hero-motion",children:"STYLE"})]}),e.jsx("p",{className:"hero-description landing-hero-motion mb-7 max-w-xl text-base font-light leading-relaxed text-white/90 sm:mb-12 sm:max-w-2xl sm:text-lg md:text-xl lg:text-2xl",children:"Discover refined footwear and atelier-grade repair services, curated for people who wear confidence with every step."}),e.jsxs("div",{className:"landing-hero-motion hero-actions flex w-full max-w-sm flex-col gap-3 sm:max-w-none sm:flex-row sm:gap-4",children:[e.jsxs(r,{href:route("products"),className:`${b} ${y}`,children:["Shop Collection",e.jsx("svg",{className:"h-5 w-5 transition-transform duration-300 group-hover:translate-x-1",fill:"none",stroke:"currentColor",viewBox:"0 0 24 24",children:e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M17 8l4 4m0 0l-4 4m4-4H3"})})]}),e.jsxs(r,{href:route("repair"),className:`${b} ${N}`,children:["Repair Services",e.jsxs("svg",{className:"h-5 w-5 transition-transform duration-300 group-hover:rotate-45",fill:"none",stroke:"currentColor",viewBox:"0 0 24 24",children:[e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"}),e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:2,d:"M15 12a3 3 0 11-6 0 3 3 0 016 0z"})]})]})]}),e.jsx("div",{className:"mt-6 flex items-center justify-start gap-2 sm:mt-8",children:l.map((s,a)=>e.jsx("button",{type:"button",onClick:()=>x(a),className:`h-2.5 rounded-full transition-all ${a===n?"w-8 bg-white":"w-2.5 bg-white/50 hover:bg-white/80"}`,"aria-label":`Go to slide ${a+1}`},`hero-dot-${a}`))})]})})]})}),e.jsx("section",{id:"landing-new-releases","data-scroll-reveal":!0,className:"scroll-reveal w-full bg-white py-16 text-black sm:py-24 lg:py-32",children:e.jsxs("div",{className:i,children:[e.jsxs("div",{"data-scroll-reveal":!0,className:"scroll-reveal mb-10 flex flex-col gap-6 sm:mb-16 sm:flex-row sm:items-end sm:justify-between",children:[e.jsx("h2",{className:"text-5xl font-normal tracking-[-0.06em] sm:text-7xl lg:text-8xl",children:"New releases"}),e.jsxs("div",{className:"flex flex-wrap gap-x-7 gap-y-3 text-sm font-semibold sm:gap-x-10 sm:text-base",children:[e.jsxs(r,{href:route("products"),className:"group inline-flex items-center gap-4 transition-opacity hover:opacity-60",children:["Men's products",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]}),e.jsxs(r,{href:route("products"),className:"group inline-flex items-center gap-4 transition-opacity hover:opacity-60",children:["Women's products",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})]})]}),e.jsx("div",{className:"flex snap-x snap-mandatory gap-4 overflow-x-auto pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:grid md:grid-cols-3 md:gap-7 md:overflow-visible md:pb-0",children:h.length>0?h.map((s,a)=>e.jsxs(r,{href:route("products.show",s.slug),"data-scroll-reveal":!0,"data-scroll-delay":Math.min(a*90,270),className:"scroll-reveal group min-w-[84%] snap-start sm:min-w-[58%] md:min-w-0",children:[e.jsxs("div",{className:"relative aspect-[4/5] overflow-hidden bg-[#f3f3f1]",children:[e.jsx("img",{src:s.main_image||"/images/product/product-01.jpg",alt:s.name,className:"h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105",loading:"lazy",decoding:"async",sizes:"(max-width: 767px) 84vw, (max-width: 1279px) 33vw, 30vw"}),a===0&&e.jsx("span",{className:"absolute right-4 top-4 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-black sm:right-5 sm:top-5",children:"New"}),s.stock_quantity===0&&e.jsx("div",{className:"absolute inset-0 flex items-center justify-center bg-black/65",children:e.jsx("span",{className:"text-sm font-semibold uppercase tracking-[0.12em] text-white sm:text-base",children:"Out of stock"})})]}),e.jsxs("div",{className:"flex items-start justify-between gap-4 border-b border-black/15 py-4 sm:py-5",children:[e.jsxs("div",{className:"min-w-0",children:[e.jsx("h3",{className:"truncate text-sm font-semibold sm:text-base",children:s.name}),e.jsx("p",{className:"mt-1 line-clamp-1 text-xs text-black/55 sm:text-sm",children:s.description||"Premium footwear for every step."})]}),e.jsxs("span",{className:"shrink-0 text-sm font-medium sm:text-base",children:["₱",s.price.toLocaleString()]})]})]},s.id)):e.jsx("div",{className:"w-full py-12 text-center md:col-span-full",children:e.jsx("p",{className:"text-lg text-black/50",children:"No products available at the moment."})})}),e.jsx("div",{"data-scroll-reveal":!0,className:"scroll-reveal mt-10 sm:mt-14",children:e.jsxs(r,{href:route("products"),className:"group inline-flex items-center gap-4 border-b border-black pb-2 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60",children:["View all products",e.jsx("span",{"aria-hidden":"true",className:"text-xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})})]})}),e.jsx("section",{id:"landing-categories","data-scroll-reveal":!0,className:"scroll-reveal w-full bg-white pb-16 text-black sm:pb-24 lg:pb-32",children:e.jsxs("div",{className:i,children:[e.jsxs("div",{"data-scroll-reveal":!0,className:"scroll-reveal mb-10 flex flex-col gap-5 sm:mb-16 sm:flex-row sm:items-end sm:justify-between",children:[e.jsx("h2",{className:"text-5xl font-normal tracking-[-0.06em] sm:text-7xl lg:text-8xl",children:"Shop by category"}),e.jsxs(r,{href:route("products"),className:"group inline-flex items-center gap-4 text-sm font-semibold transition-opacity hover:opacity-60 sm:text-base",children:["Explore the collection",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})]}),e.jsx("div",{className:"grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3",children:_.map((s,a)=>e.jsxs(r,{href:route(s.routeName),"data-scroll-reveal":!0,"data-scroll-delay":Math.min(a*100,200),className:"scroll-reveal group relative min-h-[30rem] overflow-hidden bg-[#e7e7e3] sm:min-h-[38rem]",children:[e.jsx("img",{src:s.image,alt:s.alt,className:"absolute inset-0 h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105",loading:"lazy",decoding:"async"}),e.jsx("div",{className:"absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"}),e.jsxs("div",{className:"absolute inset-x-6 bottom-6 flex items-center justify-between gap-4 text-white sm:inset-x-8 sm:bottom-8",children:[e.jsx("h3",{className:"text-xl font-semibold sm:text-2xl",children:s.title}),e.jsx("span",{"aria-hidden":"true",className:"flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/20 text-2xl font-light backdrop-blur-sm transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})]},s.title))})]})}),e.jsx("section",{id:"landing-story","data-scroll-reveal":!0,className:"scroll-reveal w-full bg-black text-white",children:e.jsxs("div",{className:"relative min-h-[34rem] overflow-hidden sm:min-h-[44rem]",children:[e.jsx("img",{src:"/images/shop/p4.jpg",alt:"SoleSpace craftsmanship in motion",className:"absolute inset-0 h-full w-full object-cover opacity-75",loading:"lazy",decoding:"async"}),e.jsx("div",{className:"absolute inset-0 bg-gradient-to-r from-black/80 via-black/35 to-black/25"}),e.jsx("div",{className:`${i} relative flex min-h-[34rem] items-end pb-10 pt-24 sm:min-h-[44rem] sm:pb-16 lg:pb-20`,children:e.jsxs("div",{"data-scroll-reveal":!0,className:"scroll-reveal scroll-reveal--side max-w-3xl",children:[e.jsx("p",{className:"mb-5 text-xs font-semibold uppercase tracking-[0.2em] text-white/70",children:"KEEP EVERY STEP GOING"}),e.jsx("h2",{className:"max-w-3xl text-4xl font-normal leading-[0.95] tracking-[-0.05em] sm:text-6xl lg:text-8xl",children:"Find a pair worth keeping."}),e.jsxs(r,{href:route("products"),className:"group mt-9 inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60 sm:mt-12",children:["Discover SoleSpace",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})]})})]})}),e.jsx("section",{id:"landing-benefits","data-scroll-reveal":!0,className:"scroll-reveal w-full bg-white py-20 text-black sm:py-28 lg:py-36",children:e.jsx("div",{className:i,children:e.jsxs("div",{className:"grid grid-cols-1 gap-14 text-center sm:grid-cols-3 sm:gap-8 lg:gap-20",children:[e.jsxs("div",{"data-scroll-reveal":!0,"data-scroll-delay":"0",className:"scroll-reveal",children:[e.jsx("div",{className:"mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16",children:e.jsx("svg",{className:"h-10 w-10",fill:"none",stroke:"currentColor",viewBox:"0 0 24 24","aria-hidden":"true",children:e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:1.25,d:"M3 7h11v10H3zM14 10h3l4 4v3h-7zM6 17a2 2 0 104 0M17 17a2 2 0 104 0"})})}),e.jsx("h3",{className:"mb-3 text-xl font-semibold tracking-tight sm:text-2xl",children:"Curated footwear"}),e.jsx("p",{className:"mx-auto max-w-xs text-base leading-relaxed text-black/60",children:"Pieces chosen for comfort, character, and the way you move."})]}),e.jsxs("div",{"data-scroll-reveal":!0,"data-scroll-delay":"100",className:"scroll-reveal",children:[e.jsx("div",{className:"mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16",children:e.jsx("svg",{className:"h-10 w-10",fill:"none",stroke:"currentColor",viewBox:"0 0 24 24","aria-hidden":"true",children:e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:1.25,d:"M4 5h16v14H4zM8 9h8M8 13h5M8 17h3"})})}),e.jsx("h3",{className:"mb-3 text-xl font-semibold tracking-tight sm:text-2xl",children:"Expert repairs"}),e.jsx("p",{className:"mx-auto max-w-xs text-base leading-relaxed text-black/60",children:"Thoughtful care that helps your favorite pairs go further."})]}),e.jsxs("div",{"data-scroll-reveal":!0,"data-scroll-delay":"200",className:"scroll-reveal",children:[e.jsx("div",{className:"mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16",children:e.jsx("svg",{className:"h-10 w-10",fill:"none",stroke:"currentColor",viewBox:"0 0 24 24","aria-hidden":"true",children:e.jsx("path",{strokeLinecap:"round",strokeLinejoin:"round",strokeWidth:1.25,d:"M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7zM9 12l2 2 4-4"})})}),e.jsx("h3",{className:"mb-3 text-xl font-semibold tracking-tight sm:text-2xl",children:"One space for every step"}),e.jsx("p",{className:"mx-auto max-w-xs text-base leading-relaxed text-black/60",children:"Shop, repair, and care for footwear in one considered place."})]})]})})}),e.jsx("section",{id:"landing-community","data-scroll-reveal":!0,className:"scroll-reveal w-full bg-black text-white",children:e.jsxs("div",{className:`${i} grid min-h-[34rem] grid-cols-1 gap-10 py-12 sm:min-h-[42rem] sm:py-16 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)] lg:gap-16 lg:py-20`,children:[e.jsxs("div",{className:"flex flex-col justify-between",children:[e.jsxs("div",{"data-scroll-reveal":!0,className:"scroll-reveal",children:[e.jsx("p",{className:"mb-8 text-xs font-semibold uppercase tracking-[0.2em] text-white/65",children:"JOIN THE SOLESPACE COMMUNITY"}),e.jsx("h2",{className:"max-w-5xl text-[3.4rem] font-normal leading-[0.82] tracking-[-0.07em] sm:text-7xl lg:text-[8.5rem]",children:"STEP INTO SOLESPACE"})]}),e.jsxs("div",{"data-scroll-reveal":!0,"data-scroll-delay":"120",className:"scroll-reveal mt-12 flex flex-col gap-5 sm:flex-row sm:items-center sm:gap-8",children:[e.jsxs(r,{href:route("products"),className:"group inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60",children:["Shop products",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]}),e.jsxs(r,{href:route("repair"),className:"group inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60",children:["Book a repair",e.jsx("span",{"aria-hidden":"true",className:"text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1",children:"→"})]})]})]}),e.jsxs("div",{"data-scroll-reveal":!0,className:"scroll-reveal scroll-reveal--scale relative min-h-[18rem] overflow-hidden bg-[#1b1b1b] lg:min-h-0",children:[e.jsx("img",{src:"/images/shop/p2.jpg",alt:"SoleSpace community on the move",className:"absolute inset-0 h-full w-full object-cover opacity-75 transition-transform duration-700 ease-out hover:scale-105",loading:"lazy",decoding:"async"}),e.jsx("div",{className:"absolute inset-0 bg-black/20"})]})]})})]})]}),e.jsx("div",{ref:u,"aria-hidden":"true",className:"footer-curtain-spacer pointer-events-none relative z-0"}),e.jsxs("footer",{ref:c,id:"landing-footer","aria-hidden":!o,className:`landing-footer fixed inset-x-0 bottom-0 z-0 w-full max-h-[100svh] min-h-[min(30rem,100svh)] overflow-x-hidden overflow-y-auto overscroll-auto bg-white text-black sm:min-h-[min(34rem,100svh)] ${o?"pointer-events-auto":"pointer-events-none"}`,children:[e.jsxs("div",{className:`${i} relative z-10 pt-8 sm:pt-10`,children:[e.jsxs("div",{className:"hidden grid-cols-4 gap-8 lg:grid",children:[e.jsx("div",{children:e.jsx(r,{href:route("landing"),className:"text-sm font-semibold uppercase tracking-[0.08em]",children:"SOLESPACE"})}),e.jsxs("div",{children:[e.jsx("h2",{className:"mb-4 text-xs font-semibold uppercase tracking-[0.14em]",children:"Explore"}),e.jsxs("ul",{className:"space-y-2 text-xs font-medium uppercase tracking-[0.08em]",children:[e.jsx("li",{children:e.jsx("a",{href:"#landing-new-releases",className:"footer-link",children:"New releases"})}),e.jsx("li",{children:e.jsx("a",{href:"#landing-categories",className:"footer-link",children:"Shop by category"})}),e.jsx("li",{children:e.jsx(r,{href:route("repair"),className:"footer-link",children:"Book a repair"})})]})]}),e.jsxs("div",{children:[e.jsx("h2",{className:"mb-4 text-xs font-semibold uppercase tracking-[0.14em]",children:"Support"}),e.jsxs("ul",{className:"space-y-2 text-xs font-medium uppercase tracking-[0.08em]",children:[e.jsx("li",{children:e.jsx("a",{href:"#landing-story",className:"footer-link",children:"Our story"})}),e.jsx("li",{children:e.jsx(r,{href:route("services"),className:"footer-link",children:"Care services"})}),e.jsx("li",{children:e.jsx(r,{href:route("services"),className:"footer-link",children:"Contact support"})})]})]}),e.jsxs("div",{children:[e.jsx("h2",{className:"mb-4 text-xs font-semibold uppercase tracking-[0.14em]",children:"Community"}),e.jsxs("ul",{className:"space-y-2 text-xs font-medium uppercase tracking-[0.08em]",children:[e.jsx("li",{children:e.jsx("a",{href:"#landing-community",className:"footer-link",children:"Join the community"})}),e.jsx("li",{children:e.jsx(r,{href:route("products"),className:"footer-link",children:"Shop SoleSpace"})}),e.jsx("li",{children:e.jsx(r,{href:route("services"),className:"footer-link",children:"Step in with us"})})]})]})]}),e.jsxs("div",{className:"lg:hidden",children:[e.jsx("p",{className:"mb-7 text-sm font-semibold uppercase tracking-[0.08em]",children:"SOLESPACE"}),e.jsxs("details",{className:"footer-disclosure",children:[e.jsxs("summary",{children:["Explore ",e.jsx("span",{"aria-hidden":"true",children:"+"})]}),e.jsxs("div",{className:"footer-disclosure__links",children:[e.jsx("a",{href:"#landing-new-releases",className:"footer-link",children:"New releases"}),e.jsx("a",{href:"#landing-categories",className:"footer-link",children:"Shop by category"}),e.jsx(r,{href:route("repair"),className:"footer-link",children:"Book a repair"})]})]}),e.jsxs("details",{className:"footer-disclosure",children:[e.jsxs("summary",{children:["Support ",e.jsx("span",{"aria-hidden":"true",children:"+"})]}),e.jsxs("div",{className:"footer-disclosure__links",children:[e.jsx("a",{href:"#landing-story",className:"footer-link",children:"Our story"}),e.jsx(r,{href:route("services"),className:"footer-link",children:"Care services"}),e.jsx(r,{href:route("services"),className:"footer-link",children:"Contact support"})]})]}),e.jsxs("details",{className:"footer-disclosure",children:[e.jsxs("summary",{children:["Community ",e.jsx("span",{"aria-hidden":"true",children:"+"})]}),e.jsxs("div",{className:"footer-disclosure__links",children:[e.jsx("a",{href:"#landing-community",className:"footer-link",children:"Join the community"}),e.jsx(r,{href:route("products"),className:"footer-link",children:"Shop SoleSpace"}),e.jsx(r,{href:route("services"),className:"footer-link",children:"Step in with us"})]})]})]}),e.jsxs("div",{className:"mt-12 grid grid-cols-1 gap-4 border-t border-black/15 py-5 text-[11px] font-medium uppercase tracking-[0.08em] sm:grid-cols-3 sm:gap-8",children:[e.jsx("p",{children:"Copyright © 2024 SoleSpace"}),e.jsxs("p",{children:["Shipping to ",e.jsx("span",{"aria-hidden":"true",children:"›"})," Philippines"]}),e.jsxs("p",{children:["Language ",e.jsx("span",{"aria-hidden":"true",children:"›"})," English"]})]})]}),e.jsx("div",{"aria-hidden":"true",className:"footer-wordmark",children:"SOLESPACE"})]})]}),e.jsx("style",{children:`
           .landing-page {
             --landing-footer-height: min(30rem, 100svh);
           }

           .footer-curtain-spacer {
             height: var(--landing-footer-height);
           }

           @media (min-width: 640px) {
             .landing-page {
               --landing-footer-height: min(34rem, 100svh);
             }
           }

           .footer-link {
             display: inline-block;
             transition: opacity 180ms ease, transform 180ms ease;
           }

           .footer-link:hover {
             opacity: 0.55;
             transform: translateX(3px);
           }

           .footer-link:focus-visible,
           .footer-disclosure summary:focus-visible {
             outline: 2px solid currentColor;
             outline-offset: 4px;
           }

           .footer-disclosure {
             border-top: 1px solid rgb(0 0 0 / 15%);
           }

           .footer-disclosure:last-of-type {
             border-bottom: 1px solid rgb(0 0 0 / 15%);
           }

           .footer-disclosure summary {
             display: flex;
             min-height: 44px;
             cursor: pointer;
             list-style: none;
             align-items: center;
             justify-content: space-between;
             padding: 14px 0;
             font-size: 0.75rem;
             font-weight: 600;
             letter-spacing: 0.12em;
             text-transform: uppercase;
           }

           .footer-disclosure summary::-webkit-details-marker {
             display: none;
           }

           .footer-disclosure[open] summary span {
             transform: rotate(45deg);
           }

           .footer-disclosure summary span {
             font-size: 1.25rem;
             font-weight: 400;
             line-height: 1;
             transition: transform 180ms ease;
           }

           .footer-disclosure__links {
             display: grid;
             gap: 10px;
             padding: 0 0 18px;
             font-size: 0.7rem;
             font-weight: 500;
             letter-spacing: 0.08em;
             text-transform: uppercase;
           }

           .footer-wordmark {
             position: relative;
             z-index: 0;
             width: max-content;
             min-width: 100%;
             padding: 2.2rem 0 0;
             overflow: hidden;
             white-space: nowrap;
             font-size: clamp(7rem, 25vw, 26rem);
             font-weight: 700;
             line-height: 0.68;
             letter-spacing: -0.105em;
           }

           @media (prefers-reduced-motion: reduce) {
             .footer-link,
             .footer-disclosure summary span {
               transition: none;
             }
           }

           .hero-headline-line {
            display: block;
            opacity: 0;
            transform: translate3d(0, 28px, 0);
            animation: hero-line-rise 600ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform, opacity;
          }

          .hero-line-1 {
            animation-delay: 0ms;
          }

          .hero-line-2 {
            animation-delay: 160ms;
          }

          .hero-line-3 {
            animation-delay: 320ms;
          }

          .hero-description {
            opacity: 0;
            transform: translate3d(0, 20px, 0);
            animation: hero-copy-fade 650ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 520ms;
            will-change: transform, opacity;
          }

          .hero-actions {
            opacity: 0;
            transform: translate3d(0, 20px, 0);
            animation: hero-copy-fade 650ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 700ms;
            will-change: transform, opacity;
          }

          html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion {
            animation-play-state: paused !important;
          }

          @keyframes hero-line-rise {
            from {
              opacity: 0;
              transform: translate3d(0, 28px, 0);
            }
            to {
              opacity: 1;
              transform: translate3d(0, 0, 0);
            }
          }

          @keyframes hero-copy-fade {
            from {
              opacity: 0;
              transform: translate3d(0, 20px, 0);
            }
            to {
              opacity: 1;
              transform: translate3d(0, 0, 0);
            }
          }

          @media (prefers-reduced-motion: reduce) {
            .landing-hero-motion,
            .landing-hero-motion.hero-headline-line,
            .landing-hero-motion.hero-description,
            .landing-hero-motion.hero-actions {
              animation: none !important;
              transform: none !important;
              opacity: 1 !important;
            }

          }
        `})]})};export{X as default};
