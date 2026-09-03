import {useEffect,useState} from 'react'
import {api} from '../lib/api'
import {rp,today} from '../lib/format'

export default function Products({storeId}){
  const [products,setProducts]=useState([])
  const [productDirty,setProductDirty]=useState({})
  const [variantDirty,setVariantDirty]=useState({})
  const [expanded,setExpanded]=useState({})
  const [q,setQ]=useState('')
  const [effective,setEffective]=useState(today())
  const [file,setFile]=useState(null)
  const [msg,setMsg]=useState('')
  const [loading,setLoading]=useState(false)
  const [bulk,setBulk]=useState({hpp:'',admin:''})
  const [fee,setFee]=useState({default_admin_percent:'',fixed_fee_per_order:'1250',effective_from:today()})
  const [meta,setMeta]=useState({})

  async function load(){
    if(!storeId)return
    setLoading(true)
    try{
      const d=await api(`/api/stores/${storeId}/products?q=${encodeURIComponent(q)}`)
      setProducts(d.data||[])
      setMeta(d.meta||{})
      const f=await api(`/api/stores/${storeId}/fees`)
      if(f.fees?.[0]){
        setFee({
          default_admin_percent:f.fees[0].default_admin_percent,
          fixed_fee_per_order:f.fees[0].fixed_fee_per_order,
          effective_from:today(),
        })
      }else if(d.meta?.earliest_order_date){
        setFee(x=>({...x,effective_from:d.meta.earliest_order_date}))
      }
      if(!d.meta?.has_any_cost_history&&d.meta?.earliest_order_date)setEffective(d.meta.earliest_order_date)
    }finally{setLoading(false)}
  }

  useEffect(()=>{
    setProductDirty({})
    setVariantDirty({})
    setExpanded({})
    setEffective(today())
    setFee({default_admin_percent:'',fixed_fee_per_order:'1250',effective_from:today()})
    load()
  },[storeId])

  function editProduct(id,key,value){
    setProducts(rows=>rows.map(p=>p.id===id?{...p,[key]:value}:p))
    setProductDirty(d=>({...d,[id]:true}))
  }

  function editVariant(productId,variantId,key,value){
    setProducts(rows=>rows.map(p=>p.id!==productId?p:{
      ...p,
      variants:p.variants.map(v=>v.id===variantId?{...v,[key]:value}:v),
    }))
    setVariantDirty(d=>({...d,[variantId]:true}))
  }

  function applyBulkProducts(){
    setProducts(rows=>rows.map(p=>({
      ...p,
      default_hpp:bulk.hpp!==''?bulk.hpp:p.default_hpp,
      default_admin_percent:bulk.admin!==''?bulk.admin:p.default_admin_percent,
    })))
    setProductDirty(Object.fromEntries(products.map(p=>[p.id,true])))
  }

  async function saveProducts(){
    const selected=products.filter(p=>productDirty[p.id])
    if(!selected.length)return
    const missing=selected.filter(p=>p.default_hpp===''||p.default_hpp==null)
    if(missing.length){setMsg(`${missing.length} produk belum diisi HPP. HPP produk wajib diisi.`);return}
    await api(`/api/stores/${storeId}/costs/products`,{
      method:'POST',
      body:{
        effective_from:effective,
        rows:selected.map(p=>({
          product_id:p.id,
          hpp:Number(p.default_hpp),
          admin_percent:p.default_admin_percent===''||p.default_admin_percent==null?null:Number(p.default_admin_percent),
        })),
      },
    })
    setProductDirty({})
    setMsg(`${selected.length} default biaya produk disimpan.`)
    load()
  }

  async function saveVariants(){
    const rows=[]
    for(const p of products){
      for(const v of p.variants||[]){
        if(!variantDirty[v.id])continue
        if(v.override_hpp===''||v.override_hpp==null){
          setMsg(`Override HPP untuk variasi "${v.variation_name||v.sku||v.id}" masih kosong. Kalau sama dengan produk, tidak perlu dibuat override.`)
          return
        }
        rows.push({
          variant_id:v.id,
          hpp:Number(v.override_hpp),
          admin_percent:v.override_admin_percent===''||v.override_admin_percent==null?null:Number(v.override_admin_percent),
        })
      }
    }
    if(!rows.length)return
    await api(`/api/stores/${storeId}/costs/variants`,{method:'POST',body:{effective_from:effective,rows}})
    setVariantDirty({})
    setMsg(`${rows.length} override variasi disimpan.`)
    load()
  }

  async function upload(){
    if(!file)return
    setLoading(true)
    try{
      const previewFd=new FormData()
      previewFd.append('type','products')
      previewFd.append('file',file)
      const preview=await api(`/api/stores/${storeId}/imports/preview`,{method:'POST',body:previewFd,form:true})
      const p=preview.preview
      if(!window.confirm(`Preview master produk: ${p.products} produk, ${p.variants} variasi. Lanjut import?`))return
      const fd=new FormData()
      fd.append('file',file)
      const d=await api(`/api/stores/${storeId}/imports/products`,{method:'POST',body:fd,form:true})
      setMsg(`Master produk masuk: ${d.summary.variants} variasi. Sekarang cukup isi HPP per produk; variasi hanya kalau berbeda.`)
      setFile(null)
      load()
    }catch(e){setMsg(e.message)}finally{setLoading(false)}
  }

  async function saveFee(e){
    e.preventDefault()
    await api(`/api/stores/${storeId}/fees`,{
      method:'POST',
      body:{
        ...fee,
        default_admin_percent:Number(fee.default_admin_percent||0),
        fixed_fee_per_order:Number(fee.fixed_fee_per_order||0),
      },
    })
    setMsg('Default admin toko dan biaya tetap tersimpan.')
  }

  if(!storeId)return <div className="empty">Pilih toko terlebih dahulu.</div>

  return <>
    <div className="page-head">
      <div>
        <h2>Produk, HPP & Admin</h2>
        <p>Harga transaksi tetap dari Harga Setelah Diskon di file Order. Master produk hanya identitas dan sumber HPP/admin.</p>
      </div>
    </div>

    {msg&&<div className="success-box">{msg}</div>}
    {!meta.has_any_cost_history&&meta.earliest_order_date&&<div className="info-box">Setup biaya pertama: tanggal berlaku otomatis dimulai dari order paling awal ({meta.earliest_order_date}) supaya histori ikut terhitung.</div>}

    <div className="two-col">
      <div className="card">
        <h3>1. Upload master produk</h3>
        <p className="muted">File Mass Update Sales Info Shopee.</p>
        <input type="file" accept=".xlsx" onChange={e=>setFile(e.target.files?.[0]||null)}/>
        <button className="btn primary" disabled={!file||loading} onClick={upload}>Upload Produk</button>
      </div>

      <form className="card" onSubmit={saveFee}>
        <h3>2. Default fee toko</h3>
        <p className="muted">Dipakai semua produk kecuali produk/variasi punya admin override.</p>
        <div className="form-grid">
          <label>Admin default (%)<input type="number" step="0.01" value={fee.default_admin_percent} onChange={e=>setFee({...fee,default_admin_percent:e.target.value})}/></label>
          <label>Biaya tetap / pesanan<input type="number" value={fee.fixed_fee_per_order} onChange={e=>setFee({...fee,fixed_fee_per_order:e.target.value})}/></label>
          <label>Berlaku mulai<input type="date" value={fee.effective_from} onChange={e=>setFee({...fee,effective_from:e.target.value})}/></label>
        </div>
        <button className="btn" type="submit">Simpan Fee Toko</button>
      </form>
    </div>

    <div className="card">
      <div className="section-title">
        <div>
          <h3>3. Default biaya per produk</h3>
          <p>Kalau seluruh variasi sama, cukup isi satu kali di sini. Admin produk boleh kosong → memakai admin toko.</p>
        </div>
        <div className="toolbar">
          <input placeholder="Cari produk / SKU" value={q} onChange={e=>setQ(e.target.value)}/>
          <button className="btn" onClick={load}>Cari</button>
          <label className="inline-label">Berlaku<input type="date" value={effective} onChange={e=>setEffective(e.target.value)}/></label>
          <input className="cell-input" type="number" placeholder="HPP massal" value={bulk.hpp} onChange={e=>setBulk({...bulk,hpp:e.target.value})}/>
          <input className="cell-input" type="number" step="0.01" placeholder="Admin produk" value={bulk.admin} onChange={e=>setBulk({...bulk,admin:e.target.value})}/>
          <button className="btn" onClick={applyBulkProducts} disabled={bulk.hpp===''&&bulk.admin===''}>Terapkan ke produk</button>
          <button className="btn primary" onClick={saveProducts} disabled={!Object.keys(productDirty).length}>Simpan Produk {Object.keys(productDirty).length||''}</button>
        </div>
      </div>

      <div className="table-wrap">
        <table>
          <thead><tr><th>Produk</th><th>Variasi</th><th>HPP Default</th><th>Admin Produk %</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {products.map(p=><ProductRows
              key={p.id}
              product={p}
              expanded={!!expanded[p.id]}
              toggle={()=>setExpanded(x=>({...x,[p.id]:!x[p.id]}))}
              editProduct={editProduct}
              editVariant={editVariant}
              variantDirty={variantDirty}
            />)}
            {!products.length&&!loading&&<tr><td colSpan="6" className="empty-cell">Belum ada produk.</td></tr>}
          </tbody>
        </table>
      </div>

      {Object.keys(variantDirty).length>0&&<div className="override-savebar">
        <span>{Object.keys(variantDirty).length} override variasi belum disimpan.</span>
        <button className="btn primary" onClick={saveVariants}>Simpan Override Variasi</button>
      </div>}
      {loading&&<div className="loading-inline">Memuat...</div>}
    </div>
  </>
}

function ProductRows({product:p,expanded,toggle,editProduct,editVariant,variantDirty}){
  const ready=p.default_hpp!==null&&p.default_hpp!==''
  return <>
    <tr className="product-main-row">
      <td className="wide">
        <strong>{p.product_name}</strong>
        <small className="product-code">Kode: {p.shopee_product_id}{p.parent_sku?` · SKU Induk: ${p.parent_sku}`:''}</small>
      </td>
      <td>{p.variants_count}</td>
      <td><input className="cell-input" type="number" value={p.default_hpp??''} onChange={e=>editProduct(p.id,'default_hpp',e.target.value)} placeholder="HPP / pcs"/></td>
      <td><input className="cell-input" type="number" step="0.01" value={p.default_admin_percent??''} onChange={e=>editProduct(p.id,'default_admin_percent',e.target.value)} placeholder="pakai toko"/></td>
      <td>{ready?<span className="pill good">HPP siap</span>:<span className="pill bad">HPP kosong</span>}</td>
      <td><button className="btn compact" onClick={toggle}>{expanded?'Tutup':'Variasi'}</button></td>
    </tr>
    {expanded&&(p.variants||[]).map(v=><tr key={v.id} className="variant-row">
      <td className="variant-indent">↳ {v.variation_name||'Tanpa variasi'}</td>
      <td>{v.sku||'—'}</td>
      <td>
        <input className="cell-input" type="number" value={v.override_hpp??''} onChange={e=>editVariant(p.id,v.id,'override_hpp',e.target.value)} placeholder={p.default_hpp!=null?`ikut ${rp(p.default_hpp)}`:'override'}/>
        <small className="inherit-note">Efektif: {v.override_hpp!==null&&v.override_hpp!==''?rp(v.override_hpp):(p.default_hpp!=null?rp(p.default_hpp):'belum ada')}</small>
      </td>
      <td>
        <input className="cell-input" type="number" step="0.01" value={v.override_admin_percent??''} onChange={e=>editVariant(p.id,v.id,'override_admin_percent',e.target.value)} placeholder={p.default_admin_percent!=null?`ikut ${p.default_admin_percent}%`:'ikut toko'}/>
        <small className="inherit-note">{v.override_admin_percent!==null&&v.override_admin_percent!==''?'Override variasi':(p.default_admin_percent!==null&&p.default_admin_percent!==''?'Admin produk':'Admin toko')}</small>
      </td>
      <td><span className={`pill ${variantDirty[v.id]?'warn':'neutral'}`}>{variantDirty[v.id]?'Belum disimpan':(v.override_hpp!=null?'Override':'Ikut produk')}</span></td>
      <td><span className="muted">Harga master {rp(v.current_price)}</span></td>
    </tr>)}
  </>
}
