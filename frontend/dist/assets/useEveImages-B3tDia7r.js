const o="https://images.evetech.net",l=`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="none">
  <rect width="32" height="32" rx="4" fill="#1e293b"/>
  <path d="M8 12h16M8 16h12M8 20h8" stroke="#475569" stroke-width="2" stroke-linecap="round"/>
</svg>`,r="data:image/svg+xml,"+encodeURIComponent(l);function u(){function n(e,t=32){return`${o}/types/${e}/icon?size=${t}`}function i(e,t=64){return`${o}/characters/${e}/portrait?size=${t}`}function c(e,t=32){return`${o}/corporations/${e}/logo?size=${t}`}function s(e,t=32){return`${o}/alliances/${e}/logo?size=${t}`}function a(e){const t=e.target;t.src!==r&&(t.src=r)}function g(){return r}return{getTypeIconUrl:n,getCharacterPortraitUrl:i,getCorporationLogoUrl:c,getAllianceLogoUrl:s,onImageError:a,getFallbackIconUrl:g}}export{u};
