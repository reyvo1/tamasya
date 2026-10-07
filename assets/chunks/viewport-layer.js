// Keep full-screen dialogs outside page animation and sticky-navigation stacking contexts.
import {f,t,F_} from "./vendor-react.js?v=20261007-telegram-nego-r12";
export function TamasyaFloatingLayer({children}) {
  return F_().createPortal(children,document.body);
}
let openLayers=0, previousBodyOverflow="";
export function TamasyaViewportLayer({children,className="",style,...props}) {
  const layer=f.useRef(null);
  f.useEffect(()=>{
    const previousFocus=document.activeElement;
    if(openLayers++===0){previousBodyOverflow=document.body.style.overflow;document.body.style.overflow="hidden";}
    const frame=requestAnimationFrame(()=>{
      const target=layer.current?.querySelector("button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex='0']")||layer.current;
      target?.focus({preventScroll:true});
    });
    return ()=>{cancelAnimationFrame(frame);if(--openLayers===0)document.body.style.overflow=previousBodyOverflow;if(previousFocus?.isConnected)previousFocus.focus({preventScroll:true});};
  },[]);
  const handleKeyDown=event=>{
    props.onKeyDown?.(event);
    if(event.defaultPrevented||event.key!=="Tab")return;
    const controls=Array.from(layer.current?.querySelectorAll("button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex='0']")||[]).filter(el=>el.getClientRects().length);
    if(!controls.length){event.preventDefault();layer.current?.focus();return;}
    const first=controls[0],last=controls[controls.length-1];
    if(event.shiftKey&&(document.activeElement===first||document.activeElement===layer.current)){event.preventDefault();last.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
  };
  return F_().createPortal(t.jsx("div",{...props,onKeyDown:handleKeyDown,ref:layer,role:props.role||"dialog","aria-modal":true,tabIndex:-1,className:"tamasya-viewport-layer "+className,style:{...style,zIndex:2147482500},children}),document.body);
}
