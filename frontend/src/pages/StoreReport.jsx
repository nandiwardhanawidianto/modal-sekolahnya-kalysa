import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { rp, num, pct, monthStart, today } from '../lib/format';
import Card from '../components/Card';
import DateRange from '../components/DateRange';
import Loading from '../components/Loading';
import StatusPill from '../components/StatusPill';

export default function StoreReport({ storeId }) {
  const [start, setStart] = useState(monthStart());
  const [end, setEnd] = useState(today());
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  async function load(s = start, e = end) {
    if (!storeId) return;
    setLoading(true);
    try {
      setData(await api(`/api/stores/${storeId}/report?start=${s}&end=${e}`));
      setError('');
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, [storeId]);

  if (!storeId) return <div className="empty">Pilih toko di kanan atas.</div>;
  const m = data?.metrics || {};
  const c = data?.coverage || {};
  const ads = data?.ads || {};
  const hasPending = (m.orders_pending || 0) + (m.orders_pending_settlement || 0) > 0;
  const hasData = (m.orders_total || 0) > 0 || (m.ad_spend_known || 0) > 0;

  return <>
    <div className="page-head">
      <div>
        <h2>Laporan Toko</h2>
        <p>Pending, refund, missing HPP, dan biaya iklan yang belum presisi dipisahkan supaya angka tidak terlihat rugi palsu.</p>
      </div>
      <DateRange start={start} end={end} onChange={(k, v) => k === 'start' ? setStart(v) : setEnd(v)} onSubmit={load} loading={loading} />
    </div>

    {error && <div className="error-box">{error}</div>}
    {loading && !data ? <Loading /> : data && !hasData ? <div className="empty card">Tidak ada data pada periode ini.</div> : data && <>
      {!ads.precise && <div className="info-box">{ads.message} Biaya iklan yang pasti berada penuh di filter ini: <strong>{rp(ads.known_amount)}</strong>. Profit setelah iklan sengaja tidak dihitung sampai coverage iklan presisi.</div>}
      {hasPending && <div className="info-box">Masih ada pesanan/settlement pending. Angka proyeksi menambahkan estimasi profit pending; jangan membaca profit confirmed setelah iklan sebagai hasil final periode.</div>}
      {(c.fees?.orders_missing||0)>0 && <div className="info-box">Ada {c.fees.orders_missing} order yang tanggalnya belum memiliki konfigurasi admin/fixed fee. Profit actual yang sudah settlement tetap valid, tetapi estimasi pending dan expected-vs-actual untuk order tersebut ditahan.</div>}

      <div className="metrics-grid">
        <Card title="Omzet selesai" value={rp(m.revenue_completed)} sub={`${num(m.orders_completed)} pesanan · ${num(m.qty_completed)} pcs`} />
        <Card title="Omzet pending" value={rp(m.revenue_pending)} tone="warn" sub={`${num(m.orders_pending)} pesanan · ${num(m.qty_pending)} pcs`} />
        <Card title="Batal" value={rp(m.revenue_cancelled)} sub={`${num(m.orders_cancelled)} pesanan`} />
        <Card title="Refund / Return" value={`${num(m.orders_refund)} order`} tone="warn" sub={`${num(m.returned_qty)} pcs dikembalikan`} />
        <Card title="Pendapatan Shopee aktual" value={rp(m.settlement_actual)} />
        <Card title="Refund tercatat di income" value={rp(m.buyer_refund_reported)} tone="warn" />
        <Card title="Adjustment debit" value={rp(m.adjustment_debits)} tone="bad" sub="Refund/koreksi setelah cair" />
        <Card title="Adjustment kredit" value={rp(m.adjustment_credits)} tone="good" sub="Kompensasi/koreksi positif" />
        <Card title="HPP confirmed" value={rp(m.hpp_confirmed)} />
        <Card title="Profit confirmed sebelum iklan" value={rp(m.profit_confirmed_before_ads)} tone="good" />
        <Card title="Iklan" value={m.ad_spend == null ? 'Belum presisi' : rp(m.ad_spend)} tone={m.ad_spend == null ? 'warn' : undefined} sub={m.ad_spend == null ? `Terdeteksi pasti ${rp(m.ad_spend_known)}` : 'Coverage periode lengkap'} />
        <Card title="Profit confirmed setelah iklan" value={m.profit_confirmed_after_ads == null ? 'Belum dihitung' : rp(m.profit_confirmed_after_ads)} tone={m.profit_confirmed_after_ads == null || hasPending ? 'warn' : m.profit_confirmed_after_ads >= 0 ? 'good' : 'bad'} sub={hasPending ? 'Belum termasuk profit pending' : ''} />
        <Card title="Potensi pending sebelum iklan" value={rp(m.profit_potential_pending_before_ads)} tone="warn" />
        <Card title="Proyeksi periode setelah iklan" value={m.profit_projected_after_ads == null ? 'Belum dihitung' : rp(m.profit_projected_after_ads)} tone="warn" sub="Confirmed + estimasi pending − iklan" />
        <Card title="ROAS selesai" value={m.roas_completed ?? '—'} />
        <Card title="Margin confirmed" value={pct(m.margin_confirmed_percent)} />
      </div>

      <div className="coverage card">
        <h3>Kelengkapan data</h3>
        <div className="coverage-grid">
          <div><span>Order</span><strong>{c.order_days?.percent ?? 0}%</strong><small>{c.order_days?.covered}/{c.order_days?.required} hari</small></div>
          <div><span>HPP</span><strong>{c.hpp?.percent ?? 0}%</strong><small>{c.hpp?.missing ?? 0} item belum HPP</small></div>
          <div><span>Settlement</span><strong>{c.settlement?.percent ?? 0}%</strong><small>{c.settlement?.settled_completed_orders}/{c.settlement?.completed_orders} order selesai</small></div>
          <div><span>Iklan</span><strong>{c.ads?.percent ?? 0}%</strong><small>{c.ads?.precise ? 'Presisi' : 'Belum presisi'} · {c.ads?.covered}/{c.ads?.required} hari</small></div>
          <div><span>Fee estimasi</span><strong>{c.fees?.percent ?? 0}%</strong><small>{c.fees?.orders_missing ?? 0} order belum punya fee historis</small></div>
          <div><span>Status laporan</span><strong>{c.is_final ? 'FINAL' : 'BELUM FINAL'}</strong><small>{c.is_final ? 'Data periode lengkap' : 'Masih ada data pending/kosong'}</small></div>
        </div>
      </div>

      <div className="card">
        <div className="section-title">
          <div><h3>Pesanan Pending</h3><p>Selesai belum cair dan pesanan yang belum sampai tetap terlihat di sini.</p></div>
          <StatusPill tone="warn">{data.pending.length} pesanan</StatusPill>
        </div>
        <div className="table-wrap"><table>
          <thead><tr><th>No. Pesanan</th><th>Tanggal</th><th>Status</th><th>Qty</th><th>Omzet</th><th>Estimasi profit</th><th>Keterangan</th><th>Umur</th></tr></thead>
          <tbody>{data.pending.length ? data.pending.map(x => <tr key={x.order_number}>
            <td className="mono">{x.order_number}</td><td>{x.ordered_at?.slice(0, 10)}</td><td><StatusPill tone="warn">{x.status}</StatusPill></td><td>{num(x.qty)}</td><td>{rp(x.revenue)}</td><td>{x.estimated_profit_before_ads == null ? 'HPP belum lengkap' : rp(x.estimated_profit_before_ads)}</td><td>{x.reason}</td><td>{x.age_days} hari</td>
          </tr>) : <tr><td colSpan="8" className="empty-cell">Tidak ada pending.</td></tr>}</tbody>
        </table></div>
      </div>

      {data.data_issues?.length > 0 && <div className="card">
        <div className="section-title">
          <div><h3>Profit Belum Bisa Dihitung</h3><p>Order sudah punya penghasilan, tetapi HPP belum lengkap. Order ini tidak dianggap profit Rp0 dan tidak dimasukkan ke profit confirmed.</p></div>
          <StatusPill tone="warn">{data.data_issues.length} order</StatusPill>
        </div>
        <div className="table-wrap"><table>
          <thead><tr><th>No. Pesanan</th><th>Tanggal</th><th>Status</th><th>Qty</th><th>Omzet</th><th>Masalah</th></tr></thead>
          <tbody>{data.data_issues.map(x => <tr key={x.order_number}><td className="mono">{x.order_number}</td><td>{x.ordered_at?.slice(0, 10)}</td><td>{x.status}</td><td>{num(x.qty)}</td><td>{rp(x.revenue)}</td><td>{x.issue}</td></tr>)}</tbody>
        </table></div>
      </div>}

      <div className="card">
        <h3>Selisih Expected vs Actual</h3>
        <p className="muted">Actual Penghasilan tetap menjadi sumber kebenaran. Tabel ini untuk mencari potongan/adjustment yang berbeda dari rumus admin yang kamu input.</p>
        <div className="table-wrap"><table>
          <thead><tr><th>No. Pesanan</th><th>Expected cair</th><th>Actual cair</th><th>Adjustment</th><th>Selisih</th><th>Profit sebelum iklan</th></tr></thead>
          <tbody>{data.anomalies.slice(0, 30).map(x => <tr key={x.order_number}><td className="mono">{x.order_number}</td><td>{rp(x.expected_settlement)}</td><td>{rp(x.actual_settlement)}</td><td>{rp(x.adjustments)}</td><td className={x.variance < 0 ? 'negative' : 'positive'}>{rp(x.variance)}</td><td>{rp(x.profit_before_ads)}</td></tr>)}</tbody>
        </table></div>
      </div>
    </>}
  </>;
}
