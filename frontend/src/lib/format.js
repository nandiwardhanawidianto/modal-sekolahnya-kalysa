export const rp=n=>n==null?'—':new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(n||0))
export const num=n=>new Intl.NumberFormat('id-ID').format(Number(n||0))
export const pct=n=>n==null?'—':`${Number(n).toLocaleString('id-ID',{maximumFractionDigits:2})}%`
export const today=()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`}
export const monthStart=()=>{const d=new Date();return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`}
