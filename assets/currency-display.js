/* Display only: never changes transaction values, tax, journal or export numbers. */
(function(root){
  'use strict';
  function formatNumber(value){
    if(value===null||value===undefined||value===''||!Number.isFinite(Number(value)))return '—';
    const amount=Number(value),fixed=amount.toFixed(2);
    // Decide decimal visibility after display rounding, avoiding floating-point tails.
    const fraction=!fixed.endsWith('.00');
    return new Intl.NumberFormat('id-ID',{minimumFractionDigits:fraction?2:0,maximumFractionDigits:2}).format(amount);
  }
  function formatRupiah(value){const formatted=formatNumber(value);return formatted==='—'?formatted:'Rp '+formatted;}
  root.TamasyaCurrencyDisplay=Object.freeze({formatNumber,formatRupiah});
})(window);
