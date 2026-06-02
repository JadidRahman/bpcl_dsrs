<!-- ===== BPCL DSRS Premium Footer (Developed By BPCL IT Division) ===== -->
<style>
  :root{
    --bpcl-ink:#0b1220;
    --bpcl-muted:#5b6473;
    --bpcl-line:#e9edf5;
    --bpcl-orange:#f17252;
    --bpcl-blue:#2b59ff;
    --bpcl-green:#71bf44;
  }

  .bpcl-footer{
    position: relative;
    margin-top: 26px;
    border-top: 1px solid rgba(233,237,245,.9);
    background:
      radial-gradient(680px 240px at 10% 0%, rgba(241,114,82,.10), transparent 60%),
      radial-gradient(680px 240px at 92% 0%, rgba(43,89,255,.10), transparent 60%),
      radial-gradient(520px 240px at 50% 120%, rgba(113,191,68,.09), transparent 60%),
      linear-gradient(180deg, #ffffff, #fbfcff);
    overflow: hidden;
  }

  /* subtle geometry layer */
  .bpcl-footer::before{
    content:"";
    position:absolute;
    inset:-2px;
    pointer-events:none;
    background-image: radial-gradient(rgba(16,24,40,.08) 1px, transparent 1px);
    background-size: 18px 18px;
    opacity:.25;
    mask-image: radial-gradient(closest-side, rgba(0,0,0,.9), transparent 75%);
  }

  .bpcl-footer-inner{
    position:relative;
    padding: 18px 14px;
  }

  .bpcl-footcard{
    border: 1px solid rgba(233,237,245,.95);
    border-radius: 22px;
    background: rgba(255,255,255,.75);
    backdrop-filter: blur(10px);
    box-shadow: 0 20px 60px rgba(16,24,40,.10);
    padding: 14px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
  }

  .bpcl-brand{
    display:flex;
    align-items:center;
    gap:10px;
    min-width: 240px;
  }

  .bpcl-mark{
    width: 42px;
    height: 42px;
    border-radius: 16px;
    display:grid;
    place-items:center;
    color:#fff;
    font-weight: 1000;
    letter-spacing:.5px;
    background: linear-gradient(135deg, var(--bpcl-blue), var(--bpcl-orange));
    box-shadow: 0 18px 40px rgba(43,89,255,.15);
  }

  .bpcl-brand h6{
    margin:0;
    font-weight: 1000;
    letter-spacing: -.2px;
    color: var(--bpcl-ink);
    line-height:1.1;
  }
  .bpcl-brand p{
    margin:4px 0 0;
    font-size: 12.5px;
    color: var(--bpcl-muted);
    line-height:1.55;
  }

  .bpcl-dev{
    display:flex;
    align-items:center;
    gap:10px;
    padding: 10px 12px;
    border-radius: 999px;
    border: 1px solid rgba(233,237,245,.95);
    background: #fff;
    box-shadow: 0 12px 28px rgba(16,24,40,.08);
    font-weight: 950;
    color: var(--bpcl-ink);
  }

  .bpcl-dev .dot{
    width:10px;height:10px;border-radius:999px;
    background: var(--bpcl-green);
    box-shadow: 0 0 0 7px rgba(113,191,68,.14), 0 0 18px rgba(113,191,68,.28);
  }

  .bpcl-links{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
    justify-content:flex-end;
  }

  .bpcl-pill{
    border: 1px solid rgba(233,237,245,.95);
    background: #fff;
    border-radius: 999px;
    padding: 9px 12px;
    font-weight: 900;
    font-size: 12px;
    color: var(--bpcl-muted);
    text-decoration:none;
    box-shadow: 0 10px 22px rgba(16,24,40,.06);
    transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
    display:inline-flex;
    align-items:center;
    gap:8px;
  }
  .bpcl-pill:hover{
    transform: translateY(-1px);
    border-color: rgba(43,89,255,.35);
    box-shadow: 0 16px 34px rgba(16,24,40,.10);
    color: var(--bpcl-ink);
  }

  .bpcl-copy{
    margin-top: 10px;
    text-align:center;
    font-size: 12px;
    color: rgba(91,100,115,.92);
  }

  @media (max-width: 576px){
    .bpcl-footcard{ padding: 12px; }
    .bpcl-brand{ min-width: 100%; }
    .bpcl-links{ width:100%; justify-content:flex-start; }
  }
</style>

<footer class="bpcl-footer">
  <div class="container bpcl-footer-inner">
    <div class="bpcl-footcard">
      <div class="bpcl-brand">
        <div class="bpcl-mark">DS</div>
        <div class="min-w-0">
          <h6 class="text-truncate">BPCL DSRS</h6>
          <p class="mb-0">
            Secure clinical assessment workflow • fast OMR • print-ready reports
          </p>
        </div>
      </div>

      <div class="bpcl-links">
        <!-- Optional pills (safe even if links don't exist) -->
        <a class="bpcl-pill" href="javascript:void(0)" onclick="window.scrollTo({top:0,behavior:'smooth'})">
          <span style="width:8px;height:8px;border-radius:999px;background:var(--bpcl-blue);display:inline-block"></span>
          Back to top
        </a>
        <span class="bpcl-dev">
          <span class="dot"></span>
          Developed By <span style="color:var(--bpcl-orange)">BPCL IT Division</span>
        </span>
      </div>
    </div>

    <div class="bpcl-copy">
      © <span id="bpclYear"></span> Bangladesh Psychiatric Care Ltd. • All rights reserved
    </div>
  </div>
</footer>

<!-- Bootstrap JS (Bundle) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
  (function(){
    var y = document.getElementById('bpclYear');
    if(y) y.textContent = new Date().getFullYear();
  })();
</script>
</body>
</html>