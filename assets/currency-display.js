/* UI rounds IDR to whole rupiah; exact helpers and stored money retain cents. */
(function(root){
 'use strict';
 const whole=new Intl.NumberFormat('id-ID',{maximumFractionDigits:0}),exact=new Intl.NumberFormat('id-ID',{minimumFractionDigits:2,maximumFractionDigits:2});
 function formatNumber(value,precise=false){
  if(value===null||value===undefined||value===''||!Number.isFinite(Number(value)))return '—';
  let text=(precise?exact:whole).format(Number(value));if(precise)text=text.replace(/,00$/,'');return text==='-0'?'0':text;
 }
 function formatRupiah(value,precise=false){const text=formatNumber(value,precise);return text==='—'?text:'Rp '+text;}
 root.TamasyaCurrencyDisplay=Object.freeze({formatNumber,formatRupiah,formatNumberExact:v=>formatNumber(v,true),formatRupiahExact:v=>formatRupiah(v,true)});
})(window);
