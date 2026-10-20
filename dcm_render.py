import sys
import os
import pydicom
from pydicom.pixel_data_handlers.util import apply_voi_lut
import numpy as np
from PIL import Image

def main():
    if len(sys.argv) < 3:
        sys.exit(1)
    dcm_path = sys.argv[1]
    out_path = sys.argv[2]
    is_thumb = len(sys.argv) >= 4 and sys.argv[3] == 'thumb'

    try:
        ds = pydicom.dcmread(dcm_path, force=True)
        if not hasattr(ds, 'pixel_array'):
            sys.exit(2)

        arr = ds.pixel_array

        # 1. カラー画像 (RGB / YBR など) の場合
        if arr.ndim == 3 and arr.shape[2] in [3, 4]:
            if arr.shape[2] == 4:
                arr = arr[:, :, :3]
            img = Image.fromarray(arr.astype(np.uint8), mode='RGB')
        else:
            # 2. グレースケール画像 (10bit, 12bit, 16bit, 8bit) の適正正規化
            # VOI LUT (Window Center / Window Width) の適用試行
            try:
                arr_lut = apply_voi_lut(arr, ds)
            except Exception:
                arr_lut = arr

            # Rescale Intercept / Slope の考慮 (VOI LUT 未適用時など)
            if hasattr(ds, 'RescaleIntercept') and hasattr(ds, 'RescaleSlope') and arr_lut is arr:
                try:
                    arr_lut = arr_lut * float(ds.RescaleSlope) + float(ds.RescaleIntercept)
                except Exception:
                    pass

            min_val = float(arr_lut.min())
            max_val = float(arr_lut.max())

            if max_val > min_val:
                norm_arr = ((arr_lut - min_val) / (max_val - min_val) * 255.0).astype(np.uint8)
            else:
                norm_arr = np.zeros(arr_lut.shape, dtype=np.uint8)

            # MONOCHROME1 (0が白、最大値が黒) の場合は階調反転して通常のMONOCHROME2に合わせる
            photo_interp = str(getattr(ds, 'PhotometricInterpretation', '')).strip().upper()
            if photo_interp == 'MONOCHROME1':
                norm_arr = 255 - norm_arr

            img = Image.fromarray(norm_arr, mode='L')

        if is_thumb:
            img.thumbnail((200, 200), Image.Resampling.LANCZOS)

        # 出力ディレクトリの確認
        out_dir = os.path.dirname(out_path)
        if out_dir and not os.path.exists(out_dir):
            os.makedirs(out_dir, exist_ok=True)

        img.save(out_path, format='JPEG', quality=88)
        sys.exit(0)

    except Exception as e:
        sys.stderr.write(str(e))
        sys.exit(3)

if __name__ == '__main__':
    main()
