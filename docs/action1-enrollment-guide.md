# Action1 Agent Enrollment Guide

**Organization:** Blue Mogul MSP  
**Action1 Org ID:** `c71462ee-81b4-4d6b-95cd-146475ce276a`  
**Action1 Dashboard:** https://app.action1.com

---

## Overview

Action1 is Blue Mogul's Remote Monitoring and Management (RMM) platform. This guide explains how to enroll a new client endpoint (Windows PC, server, or laptop) into the Action1 RMM agent for monitoring, patch management, and vulnerability tracking.

Each enrolled endpoint will appear in the Action1 dashboard under the appropriate client organization, where PATCHWORK monitors it for offline status, missing patches, and security vulnerabilities.

---

## Step 1: Obtain the Enrollment Package

### From the Action1 Dashboard

1. Log in to [https://app.action1.com](https://app.action1.com)
2. From the top navigation, select the target **client organization** (e.g., "GEMCOM", "Mahoney Elite Realty", "S2S Couture", or "Blue Mogul" for internal endpoints)
3. Navigate to **Endpoints** → **Deploy Agent**
4. Download the appropriate installer:
   - **Windows:** `action1_remote.exe` (MSI or EXE)
   - **macOS:** Coming soon (contact Action1 support)
   - **Linux:** Shell script installer

### Direct Enrollment URL

The enrollment package specific to Blue Mogul's organization can be found at the Action1 console:

```
https://app.action1.com/console#/c71462ee-81b4-4d6b-95cd-146475ce276a/endpoints/enroll
```

---

## Step 2: Install the Agent

### Windows (GUI Install)

1. Download `action1_remote.exe` from the Action1 dashboard
2. Right-click → **Run as Administrator**
3. Follow the installation wizard (no configuration needed — the package includes your org token)
4. The agent will automatically register with your organization

### Windows (Silent/MSI Install — for bulk deployment)

```powershell
# Download and silently install
$url = "https://app.action1.com/download/c71462ee-81b4-4d6b-95cd-146475ce276a/windows"
$installer = "$env:TEMP\action1_remote.exe"
Invoke-WebRequest -Uri $url -OutFile $installer
Start-Process -FilePath $installer -ArgumentList "/S" -Wait

# Or via PowerShell one-liner from Action1 dashboard
# (see Deploy Agent > PowerShell tab for org-specific script)
```

### Windows (Group Policy / RMM Deployment)

For bulk deployment across multiple endpoints:
1. In the Action1 dashboard, go to **Deploy Agent** → **Group Policy** tab
2. Download the MSI package
3. Deploy via Active Directory GPO or your existing deployment tool

---

## Step 3: Verify Enrollment

### In the Action1 Dashboard

1. After installation, wait 2–5 minutes for the endpoint to phone home
2. In the Action1 dashboard, navigate to **Endpoints**
3. The new endpoint should appear with:
   - **Status:** Online (green dot)
   - **Last seen:** Current time
   - **OS:** Windows version detected
   - **Hostname:** Computer name
   - **IP Address:** Internal IP

### Expected Dashboard State After Enrollment

| Field | Expected Value |
|-------|---------------|
| Online Status | 🟢 Online |
| Agent Version | Latest (auto-updates) |
| OS Compliance | Pending (initial scan runs within 30 min) |
| Patch Status | Scanning... (initial report within 1 hour) |
| Vulnerability Scan | Pending (first scan within 24 hours) |

---

## Step 4: Assign to Client Organization

If the endpoint should be tracked under a specific client (not the default Blue Mogul org):

1. In the Action1 dashboard, find the newly enrolled endpoint
2. Click the endpoint name → **Move to Organization**
3. Select the appropriate client organization:
   - **GEMCOM** (`196806c0-1e79-11f1-b8e9-9f8a31344ea9`)
   - **Mahoney Elite Realty** (`35c1e720-19ae-11f1-9516-eb8ab53f111d`)
   - **S2S Couture** (`40d04170-19ae-11f1-9516-eb8ab53f111d`)

---

## Monitoring Behavior After Enrollment

Once enrolled, PATCHWORK (Blue Mogul's automated RMM agent) monitors the endpoint:

- **Every 15 minutes:** Checks if the endpoint is online; alerts Matrix #alerts if offline > 15 min
- **Daily at 8 AM CT:** Generates a patch compliance report for all enrolled endpoints
- **Weekly (Monday 9 AM CT):** Generates a vulnerability report for all critical/high items

Alerts are sent to the **#alerts** channel in Blue Mogul's Matrix homeserver.

---

## Troubleshooting

### Endpoint not appearing after installation

- Verify the device has outbound internet access (port 443 to `app.action1.com`)
- Check Windows Firewall isn't blocking the Action1 agent process
- Restart the Action1 Remote Management service: `services.msc` → Action1 Remote Management → Restart

### Agent shows offline immediately after enrollment

- The device may be behind a strict proxy — configure proxy settings in Action1 agent settings
- Verify DNS resolution: `nslookup app.action1.com`

### Wrong organization assignment

- Use **Move to Organization** in the Action1 dashboard to reassign the endpoint

---

## Related Resources

- Action1 Documentation: https://help.action1.com
- Blue Mogul Org Dashboard: https://app.action1.com/console#/c71462ee-81b4-4d6b-95cd-146475ce276a/endpoints
- PATCHWORK N8N Workflow: https://n8n.bluemogul.us (workflow: PATCHWORK RMM Bridge v1)
