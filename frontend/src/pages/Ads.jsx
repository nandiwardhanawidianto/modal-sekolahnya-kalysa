import {useEffect,useState} from 'react';
import {api} from '../lib/api';
import {rp,monthStart,today} from '../lib/format';

export default function Ads({storeId}){
  const[start,setStart]=useState(monthStart());
  const[end,setEnd]=useState(today());
  const[amount,setAmount]=useState('');
  const[note,setNote]=useState('');
  const[replace,setReplace]=useState(false);
  const[rows,setRows]=useState([]);
  const[resolution,setResolution]=useState(null);
  const[msg,setMsg]=useState('');

  async function load(){
    if(!storeId)return;
    const d=await api(`/api/stores/${storeId}/ads?start=${start}&end=${end}`);
    setRows(d.rows||[]);setResolution(d.resolution||null);
  }
  useEffect(()=>{load()},[storeId]);

  async function save(e){
    e.preventDefault();setMsg('');
    try{
      await api(`/api/stores/${storeId}/ads/range`,{method:'POST',body:{start_date:start,end_date:end,total_amount:Number(amount||0),note,replace}});
      setMsg('Biaya iklan tersimpan sebagai satu periode utuh. Nominal tidak dibagi rata ke hari.');
      setAmount('');setReplace(false);await load();
    }catch(e){setMsg(e.message)}
  }

  async function remove(id){
    if(!window.confirm('Hapus periode biaya iklan ini?'))return;
    try{await api(`/api/stores/${storeId}/ads/${id}`,{method:'DELETE'});setMsg('Periode iklan dihapus.');await load()}catch(e){setMsg(e.message)}
  }

  if(!storeId)return <div className="empty">Pilih toko terlebih dahulu.</div>;
  return <>
    <div className="page-head"><div><h2>Biaya Iklan</h2><p>Simpan biaya sesuai periode aslinya. Untuk laporan custom, sistem hanya mengurangi iklan jika coverage tanggalnya benar-benar presisi.</p></div></div>
    {msg&&<div className="info-box">{msg}</div>}
    <div className="two-col">
      <form className="card" onSubmit={save}>
        <h3>Input manual periode</h3>
        <div className="form-grid">
          <label>Dari<input type="date" value={start} onChange={e=>setStart(e.target.value)}/></label>
          <label>Sampai<input type="date" value={end} onChange={e=>setEnd(e.target.value)}/></label>
          <label>Total biaya iklan<input type="number" min="0" value={amount} onChange={e=>setAmount(e.target.value)} placeholder="0"/></label>
          <label>Catatan<input value={note} onChange={e=>setNote(e.target.value)} placeholder="Opsional"/></label>
        </div>
        <label className="check"><input type="checkbox" checked={replace} onChange={e=>setReplace(e.target.checked)}/> Ganti periode lama yang seluruhnya tercakup range baru</label>
        <button className="btn primary">Simpan</button>
      </form>
      <div className="card">
        <h3>Status iklan untuk filter ini</h3>
        <div className="big-number">{resolution?.precise?rp(resolution.amount):'Belum presisi'}</div>
        <p className="muted">{resolution?.message||'Belum ada data.'}</p>
        {resolution&&!resolution.precise&&<p className="muted" style={{marginTop:8}}>Biaya dari periode yang sepenuhnya berada di filter: <strong>{rp(resolution.known_amount)}</strong> · Coverage {resolution.coverage_percent}%.</p>}
        <button className="btn" onClick={load} style={{marginTop:12}}>Refresh</button>
      </div>
    </div>
    {resolution?.partial_periods?.length>0&&<div className="info-box">Ada periode iklan yang melewati batas filter. Sistem sengaja tidak mengambil sebagian nominalnya karena tidak ada breakdown harian.</div>}
    <div className="card"><h3>Periode yang tersimpan</h3><div className="table-wrap"><table>
      <thead><tr><th>Periode</th><th>Biaya</th><th>Sumber</th><th>Catatan / File</th><th>Aksi</th></tr></thead>
      <tbody>{rows.length?rows.map(x=><tr key={x.id}><td>{String(x.start_date).slice(0,10)} → {String(x.end_date).slice(0,10)}</td><td>{rp(x.amount)}</td><td>{x.source==='shopee_csv'?'CSV Shopee':'Manual'}</td><td className="wide">{x.source_filename||x.note||'—'}</td><td><button className="btn danger" onClick={()=>remove(x.id)}>Hapus</button></td></tr>):<tr><td colSpan="5" className="empty-cell">Belum ada biaya iklan pada range ini.</td></tr>}</tbody>
    </table></div></div>
  </>;
}
