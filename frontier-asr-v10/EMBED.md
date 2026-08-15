# fiber.bluemogul.biz — Check Availability Integration

## Option 1: Direct Link (Simplest)
Just link to the qualification page directly:

  https://uisp.bluemogul.us/crm/_plugins/frontier-asr/qualify.php

This is a full standalone page with Blue Mogul branding, hero section, form, and footer.

---

## Option 2: Embedded iframe (Seamless)
Embed the form directly inside fiber.bluemogul.biz — add this HTML wherever you want the form to appear:

```html
<!-- Blue Mogul Fiber Availability Check -->
<iframe
  src="https://uisp.bluemogul.us/crm/_plugins/frontier-asr/qualify.php?embed=1"
  width="100%"
  height="600"
  frameborder="0"
  scrolling="auto"
  style="border-radius:12px;max-width:560px;display:block;margin:0 auto"
  title="Check Fiber Availability">
</iframe>
```

---

## Option 3: Button that opens a modal popup
Add this anywhere on fiber.bluemogul.biz:

```html
<!-- Availability Check Button + Modal -->
<button onclick="document.getElementById('bm-modal').style.display='flex'"
  style="background:#1565c0;color:#fff;padding:14px 28px;border:none;border-radius:8px;font-size:16px;font-weight:bold;cursor:pointer">
  🔍 Check Availability
</button>

<div id="bm-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative">
    <button onclick="document.getElementById('bm-modal').style.display='none'"
      style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:24px;cursor:pointer;color:#666">✕</button>
    <iframe
      src="https://uisp.bluemogul.us/crm/_plugins/frontier-asr/qualify.php?embed=1"
      width="100%" height="580" frameborder="0" title="Check Fiber Availability">
    </iframe>
  </div>
</div>
```

---

## CRM Client Widget
The widget.php file is registered in the manifest and will appear as a panel on each
client's detail page in UCRM. It auto-fills the client's address and shows a
"Check Availability" quick-check button plus a full order form.

Client URL parameters passed by UCRM:
  - clientId
  - clientName
  - street1, city, state, zip
  - accountNumber
