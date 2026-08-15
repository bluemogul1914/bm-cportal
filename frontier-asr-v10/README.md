# Frontier ASR UOM Ordering — UCRM Plugin

**Blue Mogul Enterprise LLC**  
Version 1.0.0

---

## What This Plugin Does

| Feature | Description |
|---|---|
| **Send Orders** | Submit ASR UOM New/Change/Disconnect orders to Frontier via SOAP |
| **Pre-Order Checks** | Verify service availability before submitting a full order |
| **Receive Responses** | CLEC webhook endpoint for Frontier to POST status updates back to you |
| **Dashboard** | View all orders, statuses, circuit IDs, and Frontier responses |
| **Logs** | Full request/response log viewer inside UCRM |

---

## Installation

### 1. Upload to UCRM

1. Zip the entire `frontier-asr/` folder:
   ```bash
   zip -r frontier-asr.zip frontier-asr/
   ```
2. Log into your UCRM admin panel at `https://uisp.bluemogul.us/crm`
3. Go to **System → Plugins → Upload Plugin**
4. Upload `frontier-asr.zip`
5. Enable the plugin

### 2. Configure Settings

In the plugin's **Settings** tab, confirm:
- **Environment**: `TEST` (start here)
- **CCNA**: `BMR`
- **Source IP**: `149.28.124.240`

### 3. Provide Endpoints to Frontier

Give Barb at Frontier Connectivity Management these values for the ASR UOM form:

| Field | Value |
|---|---|
| ORDER CLEC Endpoint URL | `https://uisp.bluemogul.us/crm/plugin/frontier-asr/public/public.php?action=receive` |
| PRE-ORDER CLEC Endpoint URL | `https://uisp.bluemogul.us/crm/plugin/frontier-asr/public/public.php?action=preorder` |
| Certificate Common Name | `uisp.bluemogul.us` |
| Source IP | `149.28.124.240` |
| CCNA | `BMR` |

These are also displayed in the plugin's **Settings** tab.

---

## File Structure

```
frontier-asr/
├── manifest.json               # UCRM plugin metadata
├── main.php                    # Admin dashboard (order UI, settings, logs)
├── public.php                  # Public SOAP/JSON endpoint for Frontier callbacks
├── src/
│   ├── FrontierASRClient.php   # Sends SOAP orders TO Frontier
│   ├── FrontierASRReceiver.php # Receives SOAP responses FROM Frontier
│   ├── OrderManager.php        # Order persistence (JSON file store)
│   └── Logger.php              # Log writer/reader
├── data/
│   ├── config.json             # Saved settings (auto-created)
│   ├── orders.json             # Order database (auto-created)
│   └── logs/
│       └── frontier-asr.log    # Activity log (auto-created)
└── README.md
```

---

## Frontier Endpoints

| Environment | URL |
|---|---|
| TEST | `https://epclec.frontier.com/asrtmlwebservice/services/asrport` |
| PRODUCTION | `https://ep.frontier.com/asrtmlwebservice/services/asrport` |

---

## Workflow

```
[UCRM Dashboard] -- New Order form --> [public.php?action=send]
                                              |
                                              v
                                  [FrontierASRClient] -- SOAP POST --> [Frontier TEST/PROD]
                                              |
                                         (async)
                                              |
                               [Frontier] -- SOAP POST --> [public.php?action=receive]
                                              |
                                              v
                                  [FrontierASRReceiver] --> [OrderManager] --> orders.json
```

---

## Upgrading to Production

1. Go to **Settings** tab → change Environment to `PRODUCTION`
2. Notify Frontier Connectivity Management to update their records
3. Test with a live order

---

## Support

Tracy Williams — tracy.williams@bluemogul.biz | (346) 309-5514
