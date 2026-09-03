import {useEffect,useState} from 'react'
import {api,apiFresh} from '../lib/api'
import {monthStart,today,num,rp} from '../lib/format'
import DateRange from '../components/DateRange'
import Card from '../components/Card'
import Loading from '../components/Loading'
import StatusPill from '../components/StatusPill'

function latestImport(rows,type){
  return (rows||[]).find(x=>x.type===type&&x.status==='completed')||null
}
function dateOnly(v){return v?String(v).slice(0,10):'Belum ada'}
function statusTone(ok,warn=false){return ok?'good':warn?'warn':'bad'}

export default function DataHealth({storeId}){
  const[start,setStart]=useState(monthStart())
  const[end,setEnd]=useState(today())
  const[data,setData]=useState(null)
  const[loading,setLoading]=useState(false)
  const[error,setError]=useState('')

  async function load(s=start,e=end,fresh=false){
    if(!storeId)return
    setLoading(true);setError('')
    const reportPath=`/api/stores/${storeId}/report?start=${s}&end=${e}`
    const importsPath=`/api/stores/${storeId}/imports`
    const adsPath=`/api/stores/${storeId}/ads?start=${s}&end=${e}`
    const getter=fresh?apiFresh:api
    try{
      const[report,imports,ads]=await Promise.all([getter(reportPath),getter(importsPath),getter(adsPath)])
      setData({report,imports:imports.batches||[],ads:ads.rows||[]})
    }catch(err){setError(err.message)}finally{setLoading(false)}
  }
  useEffect(()=>{load()},[storeId])

  if(!storeId)return <div className="empty">Pilih toko terlebih dahulu.</div>
  const r=data?.report||{}
  const m=r.metrics||{}
  const c=r.coverage||{}
  const batches=data?.imports||[]
  const latestOrders=latestImport(batches,'orders')
  const latestIncome=latestImport(batches,'income')
  const latestProducts=latestImport(batches,'products')
  const latestAdsImport=latestImport(batches,'ads')
  const latestAdsPeriod=(data?.ads||[]).reduce((best,x)=>!best||String(x.end_date)>String(best.end_date)?x:best,null)
  const issues=r.data_issues||[]
  const hppOk=(m.missing_hpp_items||0)===0&&(m.orders_missing_hpp||0)===0
  const feeOk=(m.orders_missing_fee_config||0)===0
  const settlementOk=(m.orders_pending_settlement||0)===0
  const orderCoverageOk=(c.order_days?.percent??0)>=100
  const adsOk=!!c.ads?.precise
  const allGood=hppOk&&feeOk&&settlementOk&&orderCoverageOk&&adsOk

  return <>
    <div className="page-head"><div><h2>Kesehatan Data</h2><p>Cek cepat sebelum percaya angka profit. Kalau ada masalah, cari dari sini tanpa perlu Tinker.</p></div><DateRange start={start} end={end} onChange={(k,v)=>k==='start'?setStart(v):setEnd(v)} onSubmit={load} loading={loading}/></div>
    <div className="toolbar"><button className="btn" disabled={loading} onClick={()=>load(start,end,true)}>Periksa Ulang dari Database</button></div>
    {error&&<div className="error-box">{error}</div>}
    {loading&&!data?<Loading text="Memeriksa kelengkapan data..."/>:data&&<>
      <div className={allGood?'success-box':'info-box'}>{allGood?'Data periode ini lengkap untuk komponen utama profit.':'Ada bagian data yang perlu diperhatikan. Lihat kartu di bawah.'}</div>
      <div className="metrics-grid">
        <Card title="HPP" value={hppOk?'Lengkap':`${num(m.missing_hpp_items)} item kosong`} tone={statusTone(hppOk)} sub={`${c.hpp?.percent??0}% item order punya HPP efektif`}/>
        <Card title="Penghasilan Shopee" value={settlementOk?'Lengkap':`${num(m.orders_pending_settlement)} order belum cair`} tone={statusTone(settlementOk,true)} sub={`${c.settlement?.percent??0}% order selesai sudah punya penghasilan`}/>
        <Card title="Iklan" value={adsOk?'Presisi':'Belum lengkap'} tone={statusTone(adsOk,true)} sub={`${c.ads?.percent??0}% hari tercakup · pasti ${rp(m.ad_spend_known||0)}`}/>
        <Card title="Admin / Fee" value={feeOk?'Lengkap':`${num(m.orders_missing_fee_config)} order belum punya fee`} tone={statusTone(feeOk,true)} sub={`${c.fees?.percent??0}% order punya konfigurasi`}/>
        <Card title="Coverage Order" value={orderCoverageOk?'Lengkap':`${c.order_days?.covered||0}/${c.order_days?.required||0} hari`} tone={statusTone(orderCoverageOk,true)} sub={`${c.order_days?.percent??0}% periode`}/>
        <Card title="Status Profit" value={r.coverage?.is_final?'Final':'Belum final'} tone={r.coverage?.is_final?'good':'warn'} sub={`${num(m.orders_pending||0)} order masih berstatus pending`}/>
      </div>

      <div className="card"><div className="section-title"><div><h3>Terakhir Diperbarui</h3><p>Tanggal sumber data terakhir yang sudah masuk untuk toko ini.</p></div></div><div className="table-wrap"><table><thead><tr><th>Data</th><th>Sampai tanggal</th><th>File / sumber terakhir</th></tr></thead><tbody>
        <tr><td><strong>Master Produk</strong></td><td>{dateOnly(latestProducts?.source_end_date||latestProducts?.created_at)}</td><td className="wide">{latestProducts?.original_filename||'Belum ada import'}</td></tr>
        <tr><td><strong>Order / Penjualan</strong></td><td>{dateOnly(latestOrders?.source_end_date)}</td><td className="wide">{latestOrders?.original_filename||'Belum ada import'}</td></tr>
        <tr><td><strong>Penghasilan</strong></td><td>{dateOnly(latestIncome?.source_end_date)}</td><td className="wide">{latestIncome?.original_filename||'Belum ada import'}</td></tr>
        <tr><td><strong>Iklan</strong></td><td>{dateOnly(latestAdsPeriod?.end_date||latestAdsImport?.source_end_date)}</td><td className="wide">{latestAdsPeriod?.source_filename||latestAdsImport?.original_filename||(latestAdsPeriod?'Input manual':'Belum ada data iklan')}</td></tr>
      </tbody></table></div></div>

      <div className="card"><div className="section-title"><div><h3>Masalah HPP / Data Order</h3><p>Hanya order pada periode terpilih. Produk arsip yang tidak terjual tidak akan muncul di sini.</p></div><StatusPill tone={issues.length?'warn':'good'}>{issues.length?`${issues.length} masalah`:'Aman'}</StatusPill></div>
        <div className="table-wrap"><table><thead><tr><th>No. Pesanan</th><th>Tanggal</th><th>Status</th><th>Qty</th><th>Omzet</th><th>Masalah</th></tr></thead><tbody>{issues.length?issues.map((x,i)=><tr key={`${x.order_number}-${i}`}><td className="mono">{x.order_number}</td><td>{x.ordered_at?.slice(0,10)}</td><td>{x.status}</td><td>{num(x.qty)}</td><td>{rp(x.revenue)}</td><td className="wide">{x.issue}</td></tr>):<tr><td colSpan="6" className="empty-cell">Tidak ditemukan order dengan HPP bermasalah pada periode ini.</td></tr>}</tbody></table></div>
      </div>
    </>}
  </>
}
