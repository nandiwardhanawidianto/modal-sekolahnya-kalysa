import {useEffect,useState} from 'react'
import {api} from '../lib/api'
import {rp,num,monthStart,today} from '../lib/format'
import Card from '../components/Card'
import DateRange from '../components/DateRange'
import Loading from '../components/Loading'

function Change({value,label}){
 if(value==null)return <span className="change neutral">{label}: belum bisa dibandingkan</span>
 const up=value>0,down=value<0
 return <span className={`change ${up?'up':down?'down':'neutral'}`}>{up?'↑':down?'↓':'→'} {Math.abs(value).toLocaleString('id-ID',{maximumFractionDigits:1})}% {label}</span>
}

function RevenueTrend({trend}){
 const rows=trend?.rows||[]
 if(!rows.length)return <div className="empty-chart">Belum ada data grafik.</div>
 const values=rows.map(x=>Number(x.revenue||0));const max=Math.max(...values,1);const w=760,h=220,p=28
 const pts=rows.map((r,i)=>{const x=rows.length===1?w/2:p+(i*(w-2*p)/(rows.length-1));const y=h-p-(Number(r.revenue||0)/max)*(h-2*p);return{x,y,...r}})
 const path=pts.map((pnt,i)=>`${i?'L':'M'} ${pnt.x.toFixed(1)} ${pnt.y.toFixed(1)}`).join(' ')
 return <div className="simple-chart-wrap">
   <svg className="simple-chart" viewBox={`0 0 ${w} ${h}`} role="img" aria-label="Grafik omzet">
     <line x1={p} y1={h-p} x2={w-p} y2={h-p} className="chart-axis"/>
     <path d={path} className="chart-line" fill="none"/>
     {pts.map((pnt,i)=><g key={i}><circle cx={pnt.x} cy={pnt.y} r="4" className="chart-dot"/><text x={pnt.x} y={h-8} textAnchor="middle" className="chart-label">{pnt.label}</text></g>)}
   </svg>
   <div className="chart-caption">Grafik omzet {trend.granularity==='day'?'per hari':'per minggu'} dari pesanan yang sudah dikirim/selesai.</div>
 </div>
}

export default function MiaDashboard({stores}){
 const[start,setStart]=useState(monthStart());const[end,setEnd]=useState(today());const[scope,setScope]=useState('all');const[data,setData]=useState(null);const[loading,setLoading]=useState(false);const[error,setError]=useState('')
 async function load(s=start,e=end){setLoading(true);setError('');try{const q=scope==='all'?'':`&store_id=${scope}`;setData(await api(`/api/report/mia?start=${s}&end=${e}${q}`))}catch(err){setError(err.message)}finally{setLoading(false)}}
 useEffect(()=>{load()},[scope])
 const m=data?.metrics||{};const comparison=data?.comparison||{}
 const actualSub=m.orders_missing_hpp>0?`${num(m.orders_missing_hpp)} order belum masuk profit karena HPP belum lengkap`:m.orders_missing_fee_config>0?`${num(m.orders_missing_fee_config)} order belum settle belum bisa diestimasi karena fee/admin belum lengkap`:`${m.actual_order_percent??0}% order terhitung memakai Penghasilan Shopee aktual`
 return <>
  <div className="page-head mia-head"><div><h2>Untuk Istriku Mia ❤️</h2><p>Cuma yang penting: omzet, profit setelah iklan, iklan, dan barang terjual.</p></div></div>
  <div className="mia-toolbar card"><label>Lihat<select value={scope} onChange={e=>setScope(e.target.value)}><option value="all">Semua Toko</option>{stores.map(s=><option key={s.id} value={String(s.id)}>{s.name}</option>)}</select></label><DateRange start={start} end={end} onChange={(k,v)=>k==='start'?setStart(v):setEnd(v)} onSubmit={load} loading={loading}/></div>
  {error&&<div className="error-box">{error}</div>}
  {loading&&!data?<Loading text="Menghitung ringkasan..."/>:data&&!data.has_data?<div className="empty card">Tidak ada data pada periode ini.</div>:data&&<>
    {!m.ad_spend_precise&&<div className="info-box">Biaya iklan periode ini belum lengkap/presisi. Profit setelah iklan belum ditampilkan agar tidak menebak.</div>}
    {m.orders_missing_hpp>0&&<div className="error-box">Ada {num(m.orders_missing_hpp)} order yang benar-benar belum memiliki HPP efektif. Order tersebut belum dimasukkan ke profit.</div>}
    {m.orders_missing_fee_config>0&&<div className="info-box">Ada {num(m.orders_missing_fee_config)} order belum settle yang belum bisa diestimasi karena fee/admin toko pada tanggal order belum tersedia. Order yang sudah punya Penghasilan Shopee aktual tetap dihitung.</div>}
    <div className="mia-metrics">
      <Card title="Omzet" value={rp(m.revenue)} sub="Pesanan sudah dikirim / selesai"/>
      <Card title="Profit" value={m.profit_after_ads==null?'Belum bisa dihitung':rp(m.profit_after_ads)} tone={m.profit_after_ads==null?'warn':m.profit_after_ads>=0?'good':'bad'} sub={m.profit_after_ads==null?'Butuh data iklan periode lengkap':actualSub}/>
      <Card title="Iklan" value={m.ad_spend==null?'Belum lengkap':rp(m.ad_spend)} sub={m.ad_spend==null?`Yang pasti terbaca ${rp(m.ad_spend_known)}`:'Sudah masuk pengurang profit'}/>
      <Card title="Terjual" value={`${num(m.qty_sold)} pcs`} sub={`${num(m.orders_included)} pesanan dikirim / selesai`}/>
    </div>
    <div className="comparison-strip"><Change value={comparison.profit_percent} label="profit vs periode sebelumnya"/><Change value={comparison.revenue_percent} label="omzet"/><Change value={comparison.qty_percent} label="pcs terjual"/></div>
    <div className="card mia-chart-card"><div className="section-title"><div><h3>Pergerakan Penjualan</h3><p>Grafik memakai omzet, bukan profit mingguan palsu dari pembagian iklan bulanan.</p></div></div><RevenueTrend trend={data.trend}/></div>
    <div className="card"><div className="section-title"><div><h3>Produk yang Terjual</h3><p>Diurutkan dari omzet terbesar.</p></div></div><div className="table-wrap"><table><thead><tr><th>Produk</th>{scope==='all'&&<th>Toko</th>}<th>Terjual</th><th>Omzet</th></tr></thead><tbody>{data.products.map((p,i)=><tr key={`${p.product_name}-${i}`}><td className="wide"><strong>{p.product_name}</strong></td>{scope==='all'&&<td>{p.stores_count} toko</td>}<td>{num(p.qty)} pcs</td><td>{rp(p.revenue)}</td></tr>)}</tbody></table></div></div>
    {scope==='all'&&data.stores?.length>0&&<div className="card"><h3>Ringkasan per Toko</h3><div className="table-wrap"><table><thead><tr><th>Toko</th><th>Omzet</th><th>Terjual</th><th>Iklan</th><th>Profit</th></tr></thead><tbody>{data.stores.map(s=><tr key={s.store_id}><td><strong>{s.store_name}</strong></td><td>{rp(s.revenue)}</td><td>{num(s.qty_sold)} pcs</td><td>{s.ad_spend_precise?rp(s.ad_spend):'Belum lengkap'}</td><td>{s.profit_after_ads==null?'—':rp(s.profit_after_ads)}</td></tr>)}</tbody></table></div></div>}
  </>}
 </>
}
