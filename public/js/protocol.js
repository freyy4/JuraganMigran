(()=>{
    const root=document.querySelector('[data-protocol-version]');if(!root)return;
    let dirty=false,busy=false;
    root.addEventListener('input',event=>{if(event.target.closest('form'))dirty=true;});
    root.addEventListener('change',event=>{if(event.target.closest('form'))dirty=true;});
    root.querySelector('[data-protocol-reload]')?.addEventListener('click',()=>location.reload());
    const check=async()=>{
        if(document.hidden||busy||!navigator.onLine)return;
        busy=true;
        try{
            const response=await fetch(root.dataset.protocolState,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
            if(!response.ok)return;
            const state=await response.json();
            if(state.version!==null&&Number(state.version)!==Number(root.dataset.protocolVersion)||state.gate_passed!==(root.dataset.protocolGate==='1')){
                if(!dirty){location.reload();return;}
                root.querySelector('[data-protocol-stale]').hidden=false;
            }
        }catch{}finally{busy=false;}
    };
    setInterval(check,20000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();});
})();
