import sys
import os
import struct
import json
import numpy as np

# Static Huffman Tree for Fukuda Denshi 12L anal + wave
HUFF_TREE = [
    -1, -1, -23, -1, -1, -21, -1, -1, 19, -1, -1, -16, -1, -1, -14, -1, -1, 14,
    -1, -1, 16, -1, -1, -17, -1, -1, -19, -1, -1, -22, -1, -1, -24, -1, -1, -20,
    -1, -1, -25, 15, 14, 0, 5, 4, -13, 17, 16, 13, 6, 3, -15, 18, 7, 15, 20, 19,
    17, 2, 8, -18, 21, 11, 18, 22, 1, 20, 23, 9, 21, 24, 0, 22, 25, 10, 23, -1,
    12, 23,
]
HUFF_ROOT = 13

class BitReader:
    def __init__(self, data, pos=0):
        self.data = data
        self.pos = pos

    def bit(self):
        widx = self.pos // 16
        o = widx * 2
        w = 0
        if o + 2 <= len(self.data):
            w = (self.data[o] << 8) | self.data[o + 1]
        v = (w >> (15 - (self.pos % 16))) & 1
        self.pos += 1
        return v

    def bits(self, n):
        v = 0
        for _ in range(n):
            v = (v << 1) | self.bit()
        return v

def parse_ecg_file(path):
    if not os.path.exists(path):
        return None

    with open(path, 'rb') as f:
        data = f.read()

    # Block directory info
    num_blocks = struct.unpack('>H', data[0x1e:0x20])[0]
    blocks = []
    offset = 0x70
    for b in range(num_blocks):
        entry_pos = 0x20 + b * 16
        tag = struct.unpack('>H', data[entry_pos:entry_pos+2])[0]
        length = struct.unpack('>I', data[entry_pos+4:entry_pos+8])[0]
        blocks.append((tag, offset, length))
        offset += length

    # 1. Device and Timestamp from Block 1
    device_name = "Fukuda Denshi"
    idx_dev = data.find(b'FCP-')
    if idx_dev != -1:
        device_name = data[idx_dev:idx_dev+16].split(b'\x00')[0].decode('ascii', errors='ignore')

    exam_date = ""
    # Search for year 2020-2030 (0x07e4 - 0x07ee)
    for i in range(0x70, min(len(data)-12, 0x200)):
        y_val = struct.unpack('>H', data[i:i+2])[0]
        if 2010 <= y_val <= 2035:
            m, d, h, mi, s = struct.unpack('>5H', data[i+2:i+12])
            if 1 <= m <= 12 and 1 <= d <= 31 and 0 <= h <= 23 and 0 <= mi <= 59 and 0 <= s <= 59:
                exam_date = f"{y_val:04d}/{m:02d}/{d:02d} {h:02d}:{mi:02d}:{s:02d}"
                break

    # 2. Measurements from Block 3 (Tag 0x04)
    b3 = next((b for b in blocks if b[0] == 4), None)
    measurements = {}
    if b3:
        b3_offset = b3[1]
        p = b3_offset + 0x34
        hr, rr, pr, qrs, qt, qtc_b, qtc_f, axis = struct.unpack('>8h', data[p:p+16])
        p2 = b3_offset + 0x44
        v_extra = struct.unpack('>8h', data[p2:p2+16])

        # QTcF from v_extra[2]
        qtc_f_val = v_extra[2] if v_extra[2] > 0 else qtc_f

        measurements = {
            "hr": hr,
            "rr_ms": rr,
            "rr_sec": round(rr / 1000.0, 3) if rr > 0 else 0,
            "pr_ms": pr,
            "pr_sec": round(pr / 1000.0, 3) if pr > 0 else 0,
            "qrs_ms": qrs,
            "qrs_sec": round(qrs / 1000.0, 3) if qrs > 0 else 0,
            "qt_ms": qt,
            "qt_sec": round(qt / 1000.0, 3) if qt > 0 else 0,
            "qtc_b": round(qtc_b / 1000.0, 3) if qtc_b > 0 else 0,
            "qtc_f": round(qtc_f_val / 1000.0, 3) if qtc_f_val > 0 else 0,
            "axis_deg": axis
        }

    # 3. Waveform from Block 5 (Tag 0x09)
    marker = b'\x00\x09\x02'
    mk = data.rfind(marker)
    if mk == -1:
        return None

    data_len = struct.unpack('>I', data[mk+4:mk+8])[0]
    sample_interval_us = struct.unpack('>H', data[mk+10:mk+12])[0]
    avm_nv = struct.unpack('>H', data[mk+12:mk+14])[0]
    samples_per_lead = struct.unpack('>I', data[mk+14:mk+18])[0]
    n_leads = struct.unpack('>H', data[mk+20:mk+22])[0]
    start_bit = (mk + 44) * 8

    stream_data = data[:mk + data_len]
    br = BitReader(stream_data, start_bit)
    end_bit = (len(stream_data) // 2) * 16

    flat = []
    prev2 = 0
    prev1 = 0
    total = n_leads * samples_per_lead

    while br.pos < end_bit:
        node = HUFF_ROOT
        while node >= HUFF_ROOT:
            b = br.bit()
            node = HUFF_TREE[3 * node + b]
        if node < 0:
            break

        if node <= 10:
            delta = node - 5
        elif node == 11:
            raw_b = br.bits(8)
            delta = raw_b if raw_b < 128 else raw_b - 256
        else:
            raw_w = br.bits(16)
            delta = raw_w if raw_w < 32768 else raw_w - 65536

        x = 2 * prev1 - prev2 + delta
        prev2, prev1 = prev1, x
        flat.append(x)

    if len(flat) < total:
        return None

    stride = len(flat) // n_leads
    lead_names_8 = ['I', 'II', 'V1', 'V2', 'V3', 'V4', 'V5', 'V6']
    m_leads = {}
    for i, name in enumerate(lead_names_8):
        m_leads[name] = np.array(flat[i * stride : i * stride + samples_per_lead], dtype=np.float32)

    # 12 Leads derivation
    scale_to_mv = avm_nv / 1_000_000.0
    leads_12 = {
        'I': (m_leads['I'] * scale_to_mv).tolist(),
        'II': (m_leads['II'] * scale_to_mv).tolist(),
        'III': ((m_leads['II'] - m_leads['I']) * scale_to_mv).tolist(),
        'aVR': ((-(m_leads['I'] + m_leads['II']) / 2.0) * scale_to_mv).tolist(),
        'aVL': (((m_leads['I'] - m_leads['II']) / 2.0) * scale_to_mv).tolist(),
        'aVF': (((m_leads['II'] - m_leads['I']) / 2.0) * scale_to_mv).tolist(),
    }
    for v in ['V1', 'V2', 'V3', 'V4', 'V5', 'V6']:
        leads_12[v] = (m_leads[v] * scale_to_mv).tolist()

    return {
        "device": device_name,
        "date": exam_date,
        "sample_rate_hz": 1_000_000 // sample_interval_us if sample_interval_us > 0 else 500,
        "duration_sec": round(samples_per_lead / (1_000_000 // sample_interval_us), 1) if sample_interval_us > 0 else 5.0,
        "total_samples_per_lead": samples_per_lead,
        "measurements": measurements,
        "leads": leads_12
    }

def main():
    if len(sys.argv) < 2:
        print("Usage: ecg_render.py <path_to_ecg_file> [--json | --out <img_path>]")
        sys.exit(1)

    ecg_path = sys.argv[1]
    res = parse_ecg_file(ecg_path)
    if not res:
        sys.stderr.write("Failed to parse ECG file\n")
        sys.exit(2)

    if "--json" in sys.argv or len(sys.argv) == 2:
        print(json.dumps(res, ensure_ascii=False))
        sys.exit(0)

if __name__ == '__main__':
    main()
