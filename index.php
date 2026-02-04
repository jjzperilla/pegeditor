
<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
  header("Location: ./login.html");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Capacity Pegs – HDD Pricing</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="css/styles.css">
<script src="https://cdn.jsdelivr.net/npm/handsontable@13/dist/handsontable.full.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@13/dist/handsontable.full.min.css">


</head>
<body>

  
  <button id="sidebarSlideToggle"
        class="sidebar-slide-toggle"
        aria-expanded="true">
  ☰ Menu
</button>
  <div class="app-shell">
    <aside class="sidebar">
  <div class="sidebar-content">    
  <div class="sidebar-section collapsed">
   <div class="workspace-wrapper">
  <label for="workspaceSelect" class="workspace-label">
  <svg class="workspace-ico" viewBox="0 0 24 24" aria-hidden="true">
    <path d="M3 21V3h18v18H3zm2-2h4v-4H5v4zm0-6h4V9H5v4zm0-6h4V5H5v2zm6 12h8v-2h-8v2zm0-4h8v-2h-8v2zm0-4h8V9h-8v2z"/>
  </svg>
  <b>WORKSPACE</b>
</label>
  <select id="workspaceSelect"></select>
  <div class="dd" id="workspaceDD">
  <button type="button" class="dd__btn" id="workspaceDDBtn" aria-haspopup="listbox" aria-expanded="false">
    <span class="dd__text" id="workspaceDDText">Select workspace</span>
    <svg class="dd__chev" viewBox="0 0 20 20" aria-hidden="true">
      <path d="M5.5 7.5L10 12l4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </button>

  <div class="dd__menu" id="workspaceDDMenu" role="listbox"></div>
</div>   
  <span id="workspaceRole" style="opacity:.8;" hidden></span>
</div>
 
    <button class="section-header" data-toggle="pegTableEditor">
      <span>PEG TABLE EDITOR</span>
      <span class="chevron">▼</span>
    </button>

    <div class="section-body" id="pegTableEditor">
      <button class="sidebar-action-btn">
        Open Generator
      </button>

    </div>
  </div>

  <div class="sidebar-section">
    <button class="section-header" data-toggle="capacityControls">
      <span>CAPACITY CONTROLS</span>
      <span class="chevron">▼</span>
    </button>

    <div class="section-body" id="capacityControls">

      <div class="sidebar-controls">
        <input type="text" id="newCapacityInput" placeholder="e.g., 30TB">
        <button id="addNewCapacityBtn">Add New Capacity</button>
      </div>

      <div class="sidebar-subheader">Existing Capacities</div>
      <div class="capacity-list" id="capacityList">
        <span style="color:#9ca3af;font-size:13px;">Loading capacities...</span>
      </div>

    </div>
  </div>
      </div>
<div class="sidebar-footer">
    <button id="homeBtn" class="home-btn">Home</button>
    <button id="logoutBtn" class="logout-btn">
      Logout
    </button>
  </div>
</aside>
    <main class="main">
      <div id="viewOnlyBanner">
  👀 View mode: someone else is editing this PEG right now.
</div>
      <header class="page-header">
        
        <div>
          <div class="page-title">Capacity Pegs</div>
          <p class="page-subtitle">
            For each capacity, interface, and condition, compare recent sales vs market, then tune peg points and modifiers to get final sale and buy prices.
          </p>
        </div>
        <div class="badge">Price Matrix V.3.0</div>
      </header>
 <div id="pegBreadcrumb" class="peg-breadcrumb">
  <button class="crumb-link" data-action="goHome">Home</button>

  <span id="crumbHistoryWrap" class="crumb-history hidden">
    <span class="crumb-sep">›</span>
    <button class="crumb-link" data-action="goHistory">
      Peg Data History
    </button>
  </span>

  <span id="crumbEditorWrap" class="crumb-editor hidden">
    <span class="crumb-sep">›</span>
    <span class="crumb-current">Peg Editor</span>
  </span>
</div>
    
      
      
  <!-- new graph -->
  <div class="card allcaps-card" id="allCapacityChart">
  <div class="allcaps-header">
    <div>
      <div class="allcaps-title">All Capacities — Avg PEG Over Time</div>
      <div class="allcaps-subtitle">Daily AVG price from Points History</div>
    </div>

    <div class="allcaps-controls">
      <label for="allCapsRange" style="font-size:13px;">Date Range:</label>
      <select id="allCapsRange" class="select">
        <option value="7">Last 7d</option>
        <option value="30" selected>Last 30d</option>
        <option value="90">Last 90d</option>
        <option value="180">Last 180d</option>
        <option value="365">Last 365d</option>
      </select>
      <button id="allCapsRefresh" class="all-cap-btn" hidden>Refresh</button>
    </div>
  </div>

<div class="allcaps-chartbox">
  <canvas id="allCapsChart"></canvas>
</div>

<!-- Summary below -->
<div class="cap-summary">
  <div class="cap-summary-head">
    <div class="cap-summary-title">Capacity Summary - AVG</div>
    <div class="cap-summary-note" id="capSummaryNote">—</div>
  </div>
  <div id="capSummaryList" class="cap-summary-list"></div>
</div>
</div>      
          
  <div id="chartsContainer">
    <div class="pegHeader">
        <div id="pegNameContainer" style="margin: 10px 0;">
          <label style="font-weight:600;">Configuration Name:</label>
          <input id="pegNameInput"
           type="text"
           placeholder="Enter a PEG name (optional)">
        </div>
      <div id="settingNamesContainer">
      <label style="font-weight:600;" id="settingNames">
  <span class="setting-badge" data-key="capacity"></span>
  <span class="setting-badge" data-key="drive"></span>
  <span class="setting-badge" data-key="interface"></span>
  <span class="setting-badge" data-key="condition"></span>
        </label>
        </div>
      </div>
      <section class="content-layout" id="mainEditorLayout">
        <div class="left-column">
          <section class="card" id="pegHistorySection">
            <div class="card-header">
              <div>
                <div class="card-title" id="pegHistoryTitle">Peg History</div>
                <div class="card-subtitle">
                  Click a peg point above to view its daily price history.
                </div>
              </div>
              <div class="controls-range" style="justify-content:flex-end; margin-bottom:0;">
                <label>
                  <span>Range:</span>
                  <select id="historyRangeSelect">
                    <option value="7" selected>7 days</option>
                    <option value="14">14 days</option>
                    <option value="30">30 days</option>
                    <option value="90">90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">365 days</option>
                  </select>
                </label>
              </div>
            </div>
            <div id="pegHistoryMeta">
              <strong id="pegHistoryLabel">No peg selected</strong>
              <span id="pegHistoryChannel"></span>
              <a id="pegHistoryLink" href="#" target="_blank" style="display:none; margin-left:6px;">Open listing</a>
            </div>
            <canvas id="pegHistoryChart"></canvas>
          </section>

          <section id="pegPointHistorySection" class="card">
    <div class="card-header">
        <div class="card-title">
            <div class="card-title" id="pegPointHistoryTitle">
                Adjusted PEG Point Price History
            </div>
            <div id="pegPointHistorySubtitle" class="card-subtitle">
                All PEG point prices over time
            </div>
        </div>
        <div class="controls-range">
            <label for="pegPointRangeSelect" class="sr-only">
        Date Range
      </label>
            <select id="pegPointRangeSelect" class="range-select">
        <option value="7">Last 7 days</option>
        <option value="14">Last 14 days</option>
        <option value="30" selected>Last 30 days</option>
        <option value="60">Last 60 days</option>
        <option value="120">Last 120 days</option>
        <option value="180">Last 180 days</option>
        <option value="365">Last 365 days</option>
        <option value="all">All time</option>
      </select>
        </div>
    </div>
    <div class="card-body">
        <div class="chart-container">
          <div class="peg-point-chart-wrapper">
            <canvas id="pegPointHistoryChart"></canvas>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <div id="pegPointAverages" class="peg-point-averages">
        </div>
    </div>
</section>
          
          <section class="card" id="pegInputsChart">
            <div class="card-header">
              <div>
                <div class="card-title" id="pegChartTitle">Peg Inputs</div>
                <div class="card-subtitle">
                  Bars show weight of each peg point; line points are clickable and represent individual listings.
                  Click a peg dot to see its full price history.
                </div>
              </div>
              <div class="legend-dots">
                <div class="legend-item">
                  <span class="legend-dot sale-price"></span>
                  <span>Point price</span>
                </div>
                <div class="legend-item">
                  <span class="legend-dot market-price"></span>
                  <span>Base peg</span>
                </div>
                <div class="legend-item">
                  <span class="legend-dot volume"></span>
                  <span>Weight (%)</span>
                </div>
              </div>
            </div>
            <canvas id="pegChart"></canvas>
          </section>
          
        </div>
        <aside class="card">
          <div class="card-header">
            <div>
              <div class="card-title" id="summaryTitle">Capacity Peg Snapshot</div>
              <div class="card-subtitle">Base peg, modifiers, and final buy band for the selected capacity / interface / condition.</div>
            </div>
            <div class="save-wrapper">
            <button id="savePegBtn" style="display:none;">💾 Save Data</button>
            <label id="changeIndicator"></label>
            </div>  
          </div>

          <div class="meta-grid">
            <div>
              <div class="meta-item-label">Base peg price</div>
              <div class="meta-item-value" id="summaryBasePeg">$0.00</div>
              <div class="meta-item-help">Weighted from peg inputs.</div>
            </div>
            <div>
              <div class="meta-item-label">Adjusted sale price</div>
              <div class="meta-item-value" id="summarySuggested">$0.00</div>
              <div class="meta-item-help">Base peg + total modifiers.</div>
            </div>
            <div>
              <div class="meta-item-label">Raw average</div>
              <div class="meta-item-value" id="summaryRawAvg">$0.00</div>
              <div class="meta-item-help">Simple average of all peg prices.</div>
            </div>
            <div>
              <div class="meta-item-label">Modifier total</div>
              <div class="meta-item-value" id="summaryModifiers">$0.00</div>
              <div class="meta-item-help">Sum of adds/subtracts. Sale Price & Low/High Modifier</div>
            </div>
            <div>
              <div class="meta-item-label">Low buy price</div>
              <div class="meta-item-value" id="summaryLow">$0.00</div>
              <div class="meta-item-help">Aggressive side of the band.</div>
            </div>
            <div>
              <div class="meta-item-label">High buy price</div>
              <div class="meta-item-value" id="summaryHigh">$0.00</div>
              <div class="meta-item-help">Conservative when inventory is tight.</div>
            </div>
          </div>

          <div class="controls-row">
            <label>
            <span>Drive Type:</span>
            <select id="driveTypeSelect">
            <option value="HDD">HDD</option>
            <option value="SSD">SSD</option>
          </select>
            </label>
            <label>
              <span>Interface:</span>
              <select id="interfaceSelect">
                  <option value="sata">SATA</option>
                  <option value="sas">SAS</option>
                  <option value="nvme">NVMe</option>
                  <option value="u.2">U.2</option>
                  <option value="u.3">U.3</option>
                  <option value="pcie">PCIe</option>
              </select>
            </label>
            <label>
              <span>Condition:</span>
              <select id="conditionSelect">
                <option value="new">New</option>
                <option value="recertified">Recertified</option>
                <option value="used">Used</option>
              </select>
            </label>
              <!--
<label>
              <span>Inventory:</span>
              <select id="inventoryMode">
                <option value="overstocked">Overstocked</option>
                <option value="balanced" selected>Balanced</option>
                <option value="low">Low</option>
                <option value="critical">Critical</option>
              </select>
            </label> -->
            <label for="marginPercent">Low/High Buy Margin %
  <input type="number" id="marginPercent" class="input-margin">
            </label>
          </div>
              

  <div class="peg-table-wrapper" id="pegInputsRoot">
  <div class="peg-table-title">
    <span>Peg inputs (editable)</span>
    <span>
      <button id="openPegHistoryBtn"
              class="btn-peg-history"
              type="button"
              title="Edit or add PEG data for a specific date">
        Open Peg History
      </button>
    </span>
  </div>     
  <div class="qty-toggle-wrapper">
  <input type="checkbox" class="qty-toggle" id="qtyCheckbox">    
  <label class="lb-qty">Show Qty</label>
  </div>
       
<div class="field">
  <div class="peg-table-scroll">
    <table class="peg-table peg-table-mb">
      <colgroup>
        <col>
        <col>
        <col>
        <col>
        <col id="col-qty">
        <col>
        <col>
        <col>
      </colgroup>
      <thead>
        <tr>
          <th>Label</th>
          <th>Channel</th>
          <th>URL</th>
          <th>Price</th>
          <th>Qty</th>
          <th>Weight</th>
          <th>Additional</th>
          <th>OOS</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="pegTableBody"></tbody>
    </table>
  </div>

  <button class="clear-peg-selection" id="clearPegSelectBtn">Clear Peg Selection</button>
  <label id="totalWeight">Total Weight:</label>
  <button class="peg-add-row" id="addRowBtn">+ Add peg row</button>
</div>
<div class="modifier-wrapper">
  <div class="peg-table-title">Adjusted Sale Price modifiers (optional)</div>
  <table class="peg-table modifier-table">
    <tbody id="saleModifierTableBody"></tbody>
  </table>
  <button class="peg-add-row" id="addSaleModifierBtn">
    + Add sale price modifier
  </button>
</div>
  <div class="modifier-wrapper">
    <div class="peg-table-title">Low/High Buy modifiers (optional)</div>
    <table class="peg-table modifier-table">
      <tbody id="modifierTableBody"></tbody>
    </table>
    <button class="peg-add-row" id="addModifierBtn">+ Add modifier</button>
  </div>
</div>

          
        </aside>
<div class="bottom-column">
            <section class="card right-column">
  <div class="card-header">
    <div>
      <div class="card-title" id="salesChartTitle">Select a Capacity</div>
      <div class="card-subtitle">
        Bars show units sold; lines show your price vs market average.
        (Sales table below is editable.)
      </div>
    </div>

    <div class="legend-dots">
      <div class="legend-item">
        <span class="legend-dot sale-price"></span>
        <span>Your sale price</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot market-price"></span>
        <span>Online avg</span>
      </div>
      <div class="legend-item">
        <span class="legend-dot volume"></span>
        <span>Units sold</span>
      </div>
    </div>

    <div class="card-toggle">
      <button class="chevron-toggle" id="toggleSalesCard" aria-expanded="false">
      <span class="toggle-text">Show Sales</span>
      <span class="chevron">▼</span>
    </button>
    </div>
  </div>


  <div class="sales-content hidden" id="salesContent">
    <canvas id="salesChart"></canvas>

    <div class="sales-table-wrapper">
      <div class="peg-table-title">Sales data history (editable)</div>
      <table class="peg-table">
        <thead>
          <tr>
            <th style="width: 20%;">Day</th>
            <th style="width: 25%;">Your sale price</th>
            <th style="width: 25%;">Market avg</th>
            <th style="width: 20%;">Units sold</th>
          </tr>
        </thead>
        <tbody id="salesTableBody">
          <tr>
            <td colspan="4" style="text-align:center; color: var(--text-muted);">
              No sales data available.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
</div>
</section>
</div>
<!-- ============================
     Average PEG Over Time (Main)
============================= -->
<div class="card avg-peg-card" id="avgPegCard">

  <!-- Header -->
  <div class="" style="display:flex; justify-content:space-between; align-items:center;">
    <div class="card-header-container">
      <div class="card-title" id="avgPegCardTitle">Average PEG Price Over Time</div>
      <div class="card-subtitle">
        Averaged by interface / condition
      </div>
    </div>

    <!-- Range selector -->
    <select id="avgPegRange" class="range-select">
      <option value="7">7 days</option>
      <option value="14">14 days</option>
      <option value="30" selected>30 days</option>
      <option value="60">60 days</option>
      <option value="120">120 days</option>
      <option value="180">180 days</option>
      <option value="365">365 days</option>
    </select>
  </div>

  <!-- Chart -->
  <div class="card-body" style="height:320px;">
    <canvas id="avgPegChart"></canvas>
  </div>

  <!-- Summary -->
  <div id="avgPegSummary" class="avg-peg-summary">
    <div class="summary-title">
      Average PEG (last <span id="avgPegDays">30</span> days)
    </div>

    <div id="avgPegSummaryRows"></div>
  </div>

</div>

    
      <section class="card" id="pegDataHistoryCard" style="display: none; margin-top: 10px;">
        <div class="card-header">
          <div class="card-title">Peg Data History</div>
          <div class="card-subtitle" id="historyCardSubtitle">Select a capacity to view past configurations.</div>
        </div>
        <button id="addNewPegConfigBtn" class="peg-add-row" style="margin-bottom:10px;">
    + Add New Peg Configuration
</button>
        <div class="peg-table-wrapper" style="margin-top: 0;">

<div class="peg-history-filters">
  <span>Filter:</span>
  <select id="filterDriveType">
    <option value="">All Drive Types</option>
    <option value="HDD">HDD</option>
    <option value="SSD">SSD</option>
  </select>

  <select id="filterInterface">
    <option value="">All Interfaces</option>
    <option value="sata">SATA</option>
    <option value="sas">SAS</option>
    <option value="nvme">NVMe</option>
    <option value="u.2">U.2</option>
    <option value="u.3">U.3</option>
    <option value="pcie">PCIe</option>
  </select>

  <select id="filterCondition">
    <option value="">All Conditions</option>
    <option value="new">New</option>
    <option value="used">Used</option>
    <option value="recertified">Recertified</option>
  </select>
</div>

          <table class="peg-table">
            <thead>
              <tr>
                <th style="width: 10%; text-align: center;">View</th>
                <th style="width: 12.5%;">Date Saved</th>
                <th style="width: 12.5%;">Peg Name</th>
                <th style="width: 12.5%;">Drive Type</th>
                <th style="width: 12.5%;">Base Peg Price</th>
                <th style="width: 12.5%;">Adjusted Price</th>
                <th style="width: 8%;">Low Buy Price</th>
                <th style="width: 8%;">High Buy Price</th>
                <th style="width: 12.5%; text-align: center;">Delete</th>              </tr>
            </thead>
            <tbody id="pegHistoryTableBody">
              <tr><td colspan="9" style="text-align:center; color: var(--text-muted);">No history available.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
      
      </main>
  </div>
<div id="allConditionsModal" class="modal hidden">
    <div class="modal-content">
      <h3>All Conditions Exist</h3>
      <p>
        All <strong>Drive Type</strong> and <strong>Interface</strong> configurations for this capacity already exist
        (New, Used, Recertified).
        <br><br>
        Please open <strong>Peg History</strong> to update an existing configuration.
      </p>
      <button id="closeAllConditionsModal">OK</button>
    </div>
  </div>
<div id="appAlertModal" class="modal-overlay hidden">
  <div class="modal-box small">
    <div class="modal-header">
      <p id="appAlertTitle">Notice</p>
    </div>

    <div class="modal-body" id="appAlertMessage"></div>

    <div class="modal-footer">
      <button id="appAlertOk">OK</button>
    </div>
  </div>
</div>
<div id="appConfirmModal" class="modal-overlay hidden">
  <div class="modal-box small">
    <div class="modal-header">
      <h3 id="appConfirmTitle">Confirm</h3>
    </div>

    <div class="modal-body" id="appConfirmMessage"></div>

    <div class="modal-footer">
      <button id="appConfirmCancel">Cancel</button>
      <button id="appConfirmOk" class="danger">Yes</button>
    </div>
  </div>
</div>
<!--Peg Table Editor Modal -->
<div id="pegTableModal" class="modal-overlay hidden">
  <div class="modal-box large">
    <div class="modal-header">
      <h3>Low/High Buy Price Generator</h3>
      <button id="closePegTableModal">✕</button>
    </div>
    <div class="modal-body">
      <div id="pegSheet" style="height:100%; width:100%;"></div>
    </div>
<div class="pegTableFooter">
    <button id="generatePegBuyPrices" class="pegTableGenerate">
  Generate Buy Prices
</button>
  <button id="exportPegCsvBtn" class="pegTableExport">
  Export CSV
</button>
</div>
  </div>
  
</div>

<!--peg history--> 
<div id="pegHistoryModal" class="modal-overlay hidden">
  <div class="modal-box large">

    <div class="modal-header">
      <h3>Edit / Add PEG History</h3>
      <button id="closePegHistoryModal">✕</button>
    </div>

    <div class="modal-body">
      <div class="form-group">
        <label class="label">Select Date</label>
        <input type="date" id="pegHistoryDate" />
        <div id="pegDateStatus" class="date-status"></div>
        <div id="pegSaveStatus" class="date-status"></div>
      </div>

      <div id="pegEditorContainer" class="peg-history">
          
      </div>
      <p class="hint">
    If the price is blank, it means the history is empty.
        <br>     
  Peg rows can only be added from the main editor. Add main peg row first.
</p>

    </div>

    <div class="modal-footer">
      <button class="btn-secondary" id="cancelPegHistory">Cancel</button>
      <button class="btn-primary" id="savePegHistory">Save</button>
    </div>

  </div>
  
</div>
  
  <?php
$configIdFromUrl = isset($_GET['config_id']) ? (int)$_GET['config_id'] : 0;
?>
<script>
  window.CURRENT_WORKSPACE_ID = <?= (int)($_SESSION['workspace_id'] ?? 1) ?>;
</script>  
<script>
  window.initialConfigId = <?php echo $configIdFromUrl; ?>;
  console.log("[INIT] initialConfigId =", window.initialConfigId);
</script>
  
<script src="js/auth.js"></script>  
<script type="module" src="js/api.js"></script>
<script type="module" src="js/app.js"></script>
  <script src="js/mainChart.js"></script>
</body>
</html>