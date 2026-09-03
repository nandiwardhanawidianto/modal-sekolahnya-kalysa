import {useEffect,useState} from 'react';
import {api} from '../lib/api';

function UploadBox({type,title,help,storeId,onDone,loading,accept='.xlsx'}){
  const[file,setFile]=useState(null);const[preview,setPreview]=useState(null);const[error,setError]=useState('');const[replace,setReplace]=useState(false);
  async function doPreview(){if(!file)return;setError('');const fd=new FormData();fd.append('type',type);fd.append('file',file);try{const d=await api(`/api/stores/${storeId}/imports/preview`,{method:'POST',body:fd,form:true});setPreview(d.preview)}catch(e){setPreview(null);setError(e.message)}}
  async function doImport(){if(!file)return;setError('');try{await onDone(type,file,replace);setFile(null);setPreview(null);setReplace(false)}catch(e){setError(e.message)}}
  return <div className="card"><h3>{title}</h3><p className="muted">{help}</p><input type="file" accept={accept} onChange={e=>{setFile(e.target.files?.[0]||null);setPreview(null);setError('')}}/>{error&&<div className="error-box">{error}</div>}{preview&&<div className="preview-box"><strong>Preview</strong><pre>{JSON.stringify(preview,null,2)}</pre></div>}{type==='ads'&&preview?.overlaps?.length>0&&<label className="check"><input type="checkbox" checked={replace} onChange={e=>setReplace(e.target.checked)}/> Ganti data lama yang sepenuhnya tercakup periode CSV ini</label>}<div className="toolbar"><button className="btn" disabled={!file||loading} onClick={doPreview}>Preview</button><button className="btn primary" disabled={!file||!preview||loading} onClick={doImport}>Konfirmasi Import</button></div></div>
}

export default function Imports({storeId}){
  const[history,setHistory]=useState([]);const[msg,setMsg]=useState('');const[loading,setLoading]=useState(false);
  async function load(){if(!storeId)return;const d=await api(`/api/stores/${storeId}/imports`);setHistory(d.batches||[])}
  useEffect(()=>{load()},[storeId]);
  async function rollbackBatch(id){if(!window.confirm('Rollback import ini? Data hanya akan dikembalikan jika belum disentuh import yang lebih baru.'))return;setLoading(true);try{await api(`/api/stores/${storeId}/imports/${id}/rollback`,{method:'POST'});setMsg('Import berhasil di-rollback.');await load()}catch(e){setMsg(e.message)}finally{setLoading(false)}}
  async function upload(type,file,replace=false){const fd=new FormData();fd.append('file',file);if(type==='ads')fd.append('replace',replace?'1':'0');setLoading(true);setMsg('');try{const d=await api(`/api/stores/${storeId}/imports/${type}`,{method:'POST',body:fd,form:true});const s=d.summary||{};const n=s.orders??s.settlements??s.variants??s.ads_periods??0;setMsg(`${type} berhasil: ${n} data utama diproses.`);await load()}catch(e){setMsg(e.message);throw e}finally{setLoading(false)}}
  if(!storeId)return <div className="empty">Pilih toko terlebih dahulu.</div>;
  return <><div className="page-head"><div><h2>Import Data</h2><p>Preview dulu, lalu konfirmasi. Order dan penghasilan boleh overlap; iklan disimpan sesuai periode aslinya.</p></div></div>{msg&&<div className={msg.includes('berhasil')?'success-box':'error-box'}>{msg}</div>}
    <div className="two-col">
      <UploadBox type="products" title="Master Produk" help="Mass Update Sales Info. Biasanya cukup saat ada produk/variasi baru." storeId={storeId} onDone={upload} loading={loading}/>
      <UploadBox type="orders" title="Order / Penjualan" help="Order.all. Boleh 1 hari, 1 minggu, atau 1 bulan." storeId={storeId} onDone={upload} loading={loading}/>
      <UploadBox type="income" title="Penghasilan" help="Income sudah dilepas. Sistem ikut membaca Adjustment." storeId={storeId} onDone={upload} loading={loading}/>
      <UploadBox type="ads" title="Laporan Iklan Shopee" help="Opsional. Upload CSV laporan iklan; kalau malas, biaya iklan tetap bisa diinput manual per periode di menu Iklan." accept=".csv,.txt" storeId={storeId} onDone={upload} loading={loading}/>
    </div>
    <div className="card"><h3>Riwayat Import</h3><div className="table-wrap"><table><thead><tr><th>Waktu</th><th>Jenis</th><th>File</th><th>Coverage</th><th>Status</th><th>Baris</th><th>Created</th><th>Updated</th><th>Error</th><th>Ringkasan</th><th>Aksi</th></tr></thead><tbody>{history.map(x=><tr key={x.id}><td>{x.created_at?.replace('T',' ').slice(0,16)}</td><td>{x.type}</td><td className="wide">{x.original_filename}</td><td>{x.source_start_date||'—'} → {x.source_end_date||'—'}</td><td><span className={`pill ${x.status==='completed'?'good':x.status==='failed'?'bad':'warn'}`}>{x.status}</span></td><td>{x.rows_read}</td><td>{x.created_count}</td><td>{x.updated_count}</td><td>{x.error_count}</td><td><small>{x.summary?JSON.stringify(x.summary):x.error_message}</small></td><td>{x.rollback_available?<button className="btn danger" disabled={loading} onClick={()=>rollbackBatch(x.id)}>Undo</button>:'—'}</td></tr>)}</tbody></table></div></div>
  </>;
}
