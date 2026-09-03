function pad(n){return String(n).padStart(2,'0')}
function ymd(d){return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`}
function startOfWeek(d){const x=new Date(d);const day=x.getDay()||7;x.setDate(x.getDate()-day+1);return x}
function rangeFor(key){
 const t=new Date();t.setHours(0,0,0,0)
 if(key==='today')return[ymd(t),ymd(t)]
 if(key==='yesterday'){const x=new Date(t);x.setDate(x.getDate()-1);return[ymd(x),ymd(x)]}
 if(key==='last7'){const x=new Date(t);x.setDate(x.getDate()-6);return[ymd(x),ymd(t)]}
 if(key==='thisweek')return[ymd(startOfWeek(t)),ymd(t)]
 if(key==='lastweek'){const e=startOfWeek(t);e.setDate(e.getDate()-1);const s=startOfWeek(e);return[ymd(s),ymd(e)]}
 if(key==='thismonth')return[`${t.getFullYear()}-${pad(t.getMonth()+1)}-01`,ymd(t)]
 if(key==='lastmonth'){const s=new Date(t.getFullYear(),t.getMonth()-1,1);const e=new Date(t.getFullYear(),t.getMonth(),0);return[ymd(s),ymd(e)]}
 return null
}
function monthRange(v){if(!v)return null;const [y,m]=v.split('-').map(Number);const s=new Date(y,m-1,1);const e=new Date(y,m,0);return[ymd(s),ymd(e)]}

export default function DateRange({start,end,onChange,onSubmit,loading}){
 const apply=(a,b)=>{onChange('start',a);onChange('end',b);onSubmit?.(a,b)}
 const preset=(key)=>{const r=rangeFor(key);if(r)apply(r[0],r[1])}
 const selectedMonth=start?.slice(0,7)===end?.slice(0,7)?start.slice(0,7):''
 return <div className="range-panel">
   <div className="preset-row">
     <button type="button" className="preset-btn" onClick={()=>preset('today')} disabled={loading}>Hari ini</button>
     <button type="button" className="preset-btn" onClick={()=>preset('yesterday')} disabled={loading}>Kemarin</button>
     <button type="button" className="preset-btn" onClick={()=>preset('last7')} disabled={loading}>7 hari</button>
     <button type="button" className="preset-btn" onClick={()=>preset('thisweek')} disabled={loading}>Minggu ini</button>
     <button type="button" className="preset-btn" onClick={()=>preset('lastweek')} disabled={loading}>Minggu lalu</button>
     <button type="button" className="preset-btn" onClick={()=>preset('thismonth')} disabled={loading}>Bulan ini</button>
     <button type="button" className="preset-btn" onClick={()=>preset('lastmonth')} disabled={loading}>Bulan lalu</button>
     <label className="month-picker">Pilih bulan<input type="month" value={selectedMonth} onChange={e=>{const r=monthRange(e.target.value);if(r)apply(r[0],r[1])}}/></label>
   </div>
   <form className="date-range" onSubmit={e=>{e.preventDefault();onSubmit?.(start,end)}}>
     <label>Dari<input type="date" value={start} onChange={e=>onChange('start',e.target.value)}/></label>
     <label>Sampai<input type="date" value={end} onChange={e=>onChange('end',e.target.value)}/></label>
     <button className="btn primary" disabled={loading}>Tampilkan</button>
   </form>
 </div>
}
