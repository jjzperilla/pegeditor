

export function isValidPegRow(row) {
  return (
    Number(row.qty) > 0 &&
    row.capacity &&
    row.interface &&
    row.condition
  );
}

export function updatePegRowAdjustedUI(block) {
  if (!block || !block.points) return;

  block.points.forEach((p, idx) => {
    const detailsRow = pegTableBody.querySelector(
      `.peg-details-row[data-index="${idx}"]`
    );

    if (!detailsRow) return;

    const adjustedInput =
      detailsRow.querySelector('[data-field="adjusted_peg_price"]');

    const baseSpan = detailsRow.querySelector('.base');
    const factorSpan = detailsRow.querySelector('.factor');

    if (adjustedInput) {
      adjustedInput.value =
        p.adjusted_peg_price != null
          ? Number(p.adjusted_peg_price).toFixed(2)
          : '';
    }

    if (baseSpan) {
      baseSpan.textContent = `Base: $${Number(p.adjusted_peg_price || 0).toFixed(2)}`;
    }

    if (factorSpan) {
      const mod = Number(p.peg_modifier) || 0;
      factorSpan.textContent = `Modifier: ${(1 + mod / 100).toFixed(4)}`;
    }
  });
}


export function getDriveTypeIdFromSelect() {
  const label = driveTypeSelect?.value?.toUpperCase();
  return DRIVE_TYPE_MAP[label] ?? null;
}

export function showPegHistoryLoading(message = 'Loading history…') {
  if (!pegHistoryTableBody) return;

  pegHistoryTableBody.innerHTML = `
    <tr class="peg-history-loading">
      <td colspan="9" style="text-align:center; padding:20px;">
        <div class="spinner"></div>
        <div style="margin-top:8px; color:var(--text-muted); font-size:13px;">
          ${message}
        </div>
      </td>
    </tr>
  `;
}

export function hexToRgba(hex, alpha) {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}


export function buildOOSSummaryuser({ cap, iface, cond, driveType, points }) {
  const oosPoints = (points || []).filter(p => p && (p.oos === 1 || p.oos === true));

  if (!oosPoints.length) return null;

  const subject = `OOS flagged: ${cap} | ${driveType} | ${iface} | ${cond}`;

  const bodyLines = [
    `OOS flagged in PEG Editor`,
    ``,
    `Capacity: ${cap || ''}`,
    `Drive Type: ${driveType || ''}`,
    `Interface: ${iface || ''}`,
    `Condition: ${cond || ''}`,
    ``,
    `Peg Points:`,
    ...oosPoints.map((p, i) => `- ${p.label || `(No label #${i + 1})`}`)
  ];

  return { subject, body: bodyLines.join('\n') };
}


export function updateAddConfigButtonVisibility() {
  const btnHDD = document.getElementById("addNewPegConfigBtn");
  const btnSSD = document.getElementById("addNewPegConfigBtnSSD");

  if (!btnHDD || !btnSSD) return;

  const type = driveTypeSelect.value;

  btnHDD.style.display = (type === "HDD") ? "inline-block" : "none";
  btnSSD.style.display = (type === "SSD") ? "inline-block" : "none";
}

export function toggleCapacitySection(openId) {
  const sections = [
    "capacityControls",
    "capacityControlsSSD"
  ];

  sections.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;

    if (id === openId) {
      el.classList.remove("collapsed");
    } else {
      el.classList.add("collapsed");
    }
  });
}

