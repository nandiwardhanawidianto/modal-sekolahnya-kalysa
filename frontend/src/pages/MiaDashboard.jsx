import {useEffect,useMemo,useState} from 'react'
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

function storeColor(id){
 const hue=(Number(id||1)*137.508)%360
 return `hsl(${hue.toFixed(0)} 64% 43%)`
}

const metricMeta={
 profit:{label:'Profit',format:rp},
 revenue:{label:'Omzet',format:rp},
 qty:{label:'Qty',format:v=>`${num(v)} pcs`},
 ads:{label:'Iklan',format:rp},
 margin:{label:'Margin',format:v=>v==null?'—':`${Number(v).toLocaleString('id-ID',{maximumFractionDigits:1})}%`},
}

function performanceChange(store){
 return store.profit_change_percent ?? store.revenue_change_percent
}

function pointValue(point,metric,useAfterAds){
 if(metric==='revenue')return point.revenue
 if(metric==='qty')return point.qty
 if(metric==='ads')return point.ad_spend
 if(metric==='margin')return useAfterAds?point.margin_after_ads_percent:point.margin_before_ads_percent
 if(metric==='profit')return useAfterAds?point.profit_after_ads:point.profit_before_ads
 return null
}

function MultiStoreTrend({trend,stores,metric,visibleIds,onToggle,onSolo,onShowAll,onHideAll,soloId}){
 const series=trend?.series||[]
 const selected=series.filter(s=>visibleIds.has(String(s.store_id)))
 const profitAfterAdsReady=selected.length>0&&selected.every(s=>s.profit_after_ads_bucket_precise)
 const useAfterAds=(metric==='profit'||metric==='margin')&&profitAfterAdsReady
 const values=[]
 selected.forEach(s=>s.points.forEach(p=>{const v=pointValue(p,metric,useAfterAds);if(v!=null&&Number.isFinite(Number(v)))values.push(Number(v))}))
 if(!series.length)return <div className="empty-chart">Belum ada data grafik.</div>
 if(!selected.length)return <div className="empty-chart">Semua toko sedang disembunyikan. Klik “Tampilkan Semua”.</div>
 if(!values.length)return <div className="empty-chart">Data {metricMeta[metric].label.toLowerCase()} belum presisi untuk bucket grafik ini.</div>

 const w=960,h=320,left=58,right=20,top=25,bottom=46
 const minRaw=Math.min(...values,0),maxRaw=Math.max(...values,0)
 const span=Math.max(maxRaw-minRaw,1)
 const min=minRaw-span*.08,max=maxRaw+span*.08
 const buckets=trend.buckets||[]
 const xAt=i=>buckets.length<=1?(left+(w-right-left)/2):left+(i*(w-left-right)/(buckets.length-1))
 const yAt=v=>top+((max-Number(v))/(max-min))*(h-top-bottom)
 const zeroY=yAt(0)
 const labelStep=Math.max(1,Math.ceil(buckets.length/8))

 const segmentPaths=(points)=>{
   const chunks=[];let current=[]
   points.forEach((p,i)=>{const v=pointValue(p,metric,useAfterAds);if(v==null){if(current.length)chunks.push(current);current=[];return}current.push({x:xAt(i),y:yAt(v),v,p})})
   if(current.length)chunks.push(current)
   return chunks
 }

 return <>
  <div className="store-legend-actions">
   <button className="btn compact" onClick={onShowAll}>Tampilkan Semua</button>
   <button className="btn compact" onClick={onHideAll}>Sembunyikan Semua</button>
   {soloId&&<button className="btn compact" onClick={()=>onSolo(null)}>Keluar Solo</button>}
  </div>
  <div className="store-legend">
   {stores.map(s=>{const active=visibleIds.has(String(s.store_id));return <div key={s.store_id} className={`store-legend-item ${active?'active':'off'}`}>
    <button className="legend-toggle" onClick={()=>onToggle(String(s.store_id))} title={active?'Sembunyikan dari grafik':'Tampilkan di grafik'}>
     <span className="store-color-dot" style={{background:storeColor(s.store_id)}}/>{active?'✓':'○'} {s.store_name}
    </button>
    <button className="legend-solo" onClick={()=>onSolo(String(s.store_id))}>Solo</button>
   </div>})}
  </div>
  <div className="multi-chart-wrap">
   <svg className="multi-chart" viewBox={`0 0 ${w} ${h}`} role="img" aria-label={`Grafik ${metricMeta[metric].label} per toko`}>
    <line x1={left} y1={zeroY} x2={w-right} y2={zeroY} className="chart-zero"/>
    <line x1={left} y1={h-bottom} x2={w-right} y2={h-bottom} className="chart-axis"/>
    {buckets.map((b,i)=>i%labelStep===0||i===buckets.length-1?<text key={b.start} x={xAt(i)} y={h-16} textAnchor="middle" className="chart-label">{b.label}</text>:null)}
    {selected.map(s=><g key={s.store_id}>
      {segmentPaths(s.points).map((chunk,j)=><path key={j} d={chunk.map((pt,i)=>`${i?'L':'M'} ${pt.x.toFixed(1)} ${pt.y.toFixed(1)}`).join(' ')} fill="none" stroke={storeColor(s.store_id)} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"/>) }
      {s.points.map((p,i)=>{const v=pointValue(p,metric,useAfterAds);if(v==null)return null;return <circle key={i} cx={xAt(i)} cy={yAt(v)} r="3.5" fill="#fff" stroke={storeColor(s.store_id)} strokeWidth="2.5"><title>{`${s.store_name} · ${p.label}\n${metricMeta[metric].label}: ${metricMeta[metric].format(v)}`}</title></circle>})}
    </g>)}
   </svg>
  </div>
  <div className="chart-caption">
   {metric==='profit'?(useAfterAds?'Profit setelah iklan per bucket karena coverage iklan tiap bucket lengkap.':'Profit sebelum iklan per bucket. Iklan bulanan tidak dibagi paksa ke hari/minggu.'):
    metric==='margin'?(useAfterAds?'Margin setelah iklan per bucket.':'Margin sebelum iklan per bucket karena biaya iklan tidak presisi per bucket.'):
    metric==='ads'?'Garis hanya muncul pada bucket yang biaya iklannya tersedia secara presisi.':
    `${metricMeta[metric].label} ${trend.granularity==='day'?'per hari':'per minggu'} dari pesanan yang sudah keluar dari penjual / selesai.`}
  </div>
 </>
}

function Attention({rows,onSolo}){
 if(!rows?.length)return <div className="attention-ok">✓ Tidak ada sinyal masalah utama pada periode ini.</div>
 return <div className="attention-grid">{rows.map((a,i)=><button key={`${a.store_id}-${a.type}-${i}`} className={`attention-item severity-${a.severity>=80?'high':a.severity>=60?'mid':'low'}`} onClick={()=>onSolo(String(a.store_id))}>
   <strong>{a.store_name}</strong><span>{a.message}</span><small>Klik untuk fokus toko ini di grafik</small>
 </button>)}</div>
}

function Contribution({stores,onSolo}){
 const rows=stores.filter(s=>s.profit_after_ads!=null).sort((a,b)=>Number(b.profit_after_ads)-Number(a.profit_after_ads))
 if(!rows.length)return <div className="muted">Kontribusi profit belum bisa dihitung karena data iklan belum lengkap.</div>
 const max=Math.max(...rows.map(s=>Math.abs(Number(s.profit_after_ads||0))),1)
 return <div className="contribution-list">{rows.map(s=><button key={s.store_id} className="contribution-row" onClick={()=>onSolo(String(s.store_id))}>
  <div className="contribution-head"><span><i style={{background:storeColor(s.store_id)}}/>{s.store_name}</span><strong>{rp(s.profit_after_ads)} <em>{s.profit_contribution_percent==null?'':`${s.profit_contribution_percent}%`}</em></strong></div>
  <div className="contribution-track"><span style={{width:`${Math.max(2,Math.abs(Number(s.profit_after_ads||0))/max*100)}%`,background:storeColor(s.store_id)}}/></div>
 </button>)}</div>
}

export default function MiaDashboard({stores}){
 const[start,setStart]=useState(monthStart());const[end,setEnd]=useState(today());const[scope,setScope]=useState('all');const[data,setData]=useState(null);const[loading,setLoading]=useState(false);const[error,setError]=useState('')
 const[metric,setMetric]=useState('profit');const[viewMode,setViewMode]=useState('all');const[hiddenIds,setHiddenIds]=useState(()=>new Set());const[soloId,setSoloId]=useState(null)
 async function load(s=start,e=end){setLoading(true);setError('');try{const q=scope==='all'?'':`&store_id=${scope}`;setData(await api(`/api/report/mia?start=${s}&end=${e}${q}`))}catch(err){setError(err.message)}finally{setLoading(false)}}
 useEffect(()=>{load()},[scope])
 useEffect(()=>{setHiddenIds(new Set());setSoloId(null);setViewMode('all')},[scope,data?.period?.start,data?.period?.end])
 const m=data?.metrics||{};const comparison=data?.comparison||{};const storeRows=data?.stores||[]
 const actualSub=m.orders_missing_hpp>0?`${num(m.orders_missing_hpp)} order belum masuk profit karena HPP belum lengkap`:m.orders_missing_fee_config>0?`${num(m.orders_missing_fee_config)} order belum settle belum bisa diestimasi karena fee/admin belum lengkap`:`${m.actual_order_percent??0}% order terhitung memakai Penghasilan Shopee aktual`

 const modeIds=useMemo(()=>{
  let rows=[...storeRows]
  if(viewMode==='topprofit')rows=rows.filter(s=>s.profit_after_ads!=null).sort((a,b)=>Number(b.profit_after_ads)-Number(a.profit_after_ads)).slice(0,5)
  if(viewMode==='up')rows=rows.filter(s=>(performanceChange(s)??0)>0)
  if(viewMode==='down')rows=rows.filter(s=>(performanceChange(s)??0)<0)
  return new Set(rows.map(s=>String(s.store_id)))
 },[storeRows,viewMode])
 const visibleIds=useMemo(()=>{
  if(soloId)return new Set([String(soloId)])
  return new Set([...modeIds].filter(id=>!hiddenIds.has(id)))
 },[modeIds,hiddenIds,soloId])
 const toggle=id=>{setSoloId(null);setHiddenIds(prev=>{const n=new Set(prev);n.has(id)?n.delete(id):n.add(id);return n})}
 const showAll=()=>{setSoloId(null);setViewMode('all');setHiddenIds(new Set())}
 const hideAll=()=>{setSoloId(null);setHiddenIds(new Set(storeRows.map(s=>String(s.store_id))))}
 const solo=id=>{setSoloId(id);if(id)setHiddenIds(new Set())}

 return <>
  <div className="page-head mia-head"><div><h2>Untuk Istriku Mia ❤️</h2><p>Ringkasan usaha: total semua toko, toko yang naik/turun, dan mana yang perlu perhatian.</p></div></div>
  <div className="mia-toolbar card"><label>Lihat<select value={scope} onChange={e=>setScope(e.target.value)}><option value="all">Semua Toko</option>{stores.map(s=><option key={s.id} value={String(s.id)}>{s.name}</option>)}</select></label><DateRange start={start} end={end} onChange={(k,v)=>k==='start'?setStart(v):setEnd(v)} onSubmit={load} loading={loading}/></div>
  {error&&<div className="error-box">{error}</div>}
  {loading&&!data?<Loading text="Menghitung ringkasan..."/>:data&&!data.has_data?<div className="empty card">Tidak ada data pada periode ini.</div>:data&&<>
    {!m.ad_spend_precise&&<div className="info-box">Biaya iklan periode ini belum lengkap/presisi. Profit setelah iklan belum ditampilkan agar tidak menebak.</div>}
    {m.orders_missing_hpp>0&&<div className="error-box">Ada {num(m.orders_missing_hpp)} order yang benar-benar belum memiliki HPP efektif. Order tersebut belum dimasukkan ke profit.</div>}
    {m.orders_missing_fee_config>0&&<div className="info-box">Ada {num(m.orders_missing_fee_config)} order belum settle yang belum bisa diestimasi karena fee/admin toko pada tanggal order belum tersedia. Order yang sudah punya Penghasilan Shopee aktual tetap dihitung.</div>}
    <div className="mia-metrics">
      <Card title="Omzet" value={rp(m.revenue)} sub={scope==='all'?`Gabungan ${num(storeRows.length)} toko yang punya data`:'Pesanan sudah dikirim / selesai'}/>
      <Card title="Profit" value={m.profit_after_ads==null?'Belum bisa dihitung':rp(m.profit_after_ads)} tone={m.profit_after_ads==null?'warn':m.profit_after_ads>=0?'good':'bad'} sub={m.profit_after_ads==null?'Butuh data iklan periode lengkap':actualSub}/>
      <Card title="Iklan" value={m.ad_spend==null?'Belum lengkap':rp(m.ad_spend)} sub={m.ad_spend==null?`Yang pasti terbaca ${rp(m.ad_spend_known)}`:'Sudah masuk pengurang profit'}/>
      <Card title="Terjual" value={`${num(m.qty_sold)} pcs`} sub={`${num(m.orders_included)} pesanan dikirim / selesai`}/>
    </div>
    <div className="comparison-strip"><Change value={comparison.profit_percent} label="profit vs periode sebelumnya"/><Change value={comparison.revenue_percent} label="omzet"/><Change value={comparison.qty_percent} label="pcs terjual"/></div>

    {scope==='all'&&storeRows.length>0&&<>
      <div className="mia-two-panels">
       <div className="card"><div className="section-title"><div><h3>Kontribusi Profit per Toko</h3><p>Total profit dibagi menurut toko. Klik toko untuk fokus grafik.</p></div></div><Contribution stores={storeRows} onSolo={solo}/></div>
       <div className="card"><div className="section-title"><div><h3>Perlu Perhatian</h3><p>Sinyal otomatis dari perubahan profit, omzet, margin, iklan, dan kelengkapan data.</p></div></div><Attention rows={data.attention} onSolo={solo}/></div>
      </div>
    </>}

    <div className="card mia-chart-card">
      <div className="section-title"><div><h3>Pergerakan per Toko</h3><p>Setiap toko punya warna tetap. Tidak ada batas jumlah garis; toko bisa disembunyikan kapan saja.</p></div></div>
      <div className="mia-chart-toolbar">
       <div className="metric-tabs">{Object.entries(metricMeta).map(([key,v])=><button key={key} className={`metric-tab ${metric===key?'active':''}`} onClick={()=>setMetric(key)}>{v.label}</button>)}</div>
       {scope==='all'&&<label className="chart-filter">Tampilkan<select value={viewMode} onChange={e=>{setViewMode(e.target.value);setSoloId(null);setHiddenIds(new Set())}}><option value="all">Semua toko</option><option value="topprofit">Top 5 profit</option><option value="up">Yang naik</option><option value="down">Yang turun</option></select></label>}
      </div>
      <MultiStoreTrend trend={data.trend} stores={storeRows} metric={metric} visibleIds={visibleIds} onToggle={toggle} onSolo={solo} onShowAll={showAll} onHideAll={hideAll} soloId={soloId}/>
    </div>

    {scope==='all'&&storeRows.length>0&&<div className="card mia-ranking">
      <div className="section-title"><div><h3>Ranking Toko</h3><p>Urutan default berdasarkan profit setelah iklan. Growth memakai periode sebelumnya dengan panjang yang sama.</p></div></div>
      <div className="table-wrap"><table><thead><tr><th>#</th><th>Toko</th><th>Profit</th><th>Kontribusi</th><th>Growth</th><th>Omzet</th><th>Margin</th><th>Iklan</th><th></th></tr></thead><tbody>{storeRows.map((s,i)=><tr key={s.store_id}><td>{i+1}</td><td><strong><span className="table-color-dot" style={{background:storeColor(s.store_id)}}/>{s.store_name}</strong></td><td className={s.profit_after_ads==null?'':s.profit_after_ads<0?'negative':'positive'}>{s.profit_after_ads==null?'Belum lengkap':rp(s.profit_after_ads)}</td><td>{s.profit_contribution_percent==null?'—':`${s.profit_contribution_percent}%`}</td><td><Change value={s.profit_change_percent??s.revenue_change_percent} label=""/></td><td>{rp(s.revenue)}</td><td>{s.margin_after_ads_percent==null?'—':`${s.margin_after_ads_percent}%`}</td><td>{s.ad_spend_precise?rp(s.ad_spend):'Belum lengkap'}</td><td><button className="btn compact" onClick={()=>solo(String(s.store_id))}>Fokus</button></td></tr>)}</tbody></table></div>
    </div>}

    <div className="card"><div className="section-title"><div><h3>Produk yang Terjual</h3><p>Diurutkan dari omzet terbesar.</p></div></div><div className="table-wrap"><table><thead><tr><th>Produk</th>{scope==='all'&&<th>Toko</th>}<th>Terjual</th><th>Omzet</th></tr></thead><tbody>{data.products.map((p,i)=><tr key={`${p.product_name}-${i}`}><td className="wide"><strong>{p.product_name}</strong></td>{scope==='all'&&<td>{p.stores_count} toko</td>}<td>{num(p.qty)} pcs</td><td>{rp(p.revenue)}</td></tr>)}</tbody></table></div></div>
  </>}
 </>
}
