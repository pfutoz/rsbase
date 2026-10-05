# -*- coding: utf-8 -*-
import os
import sys
import pydicom
from PIL import Image

def get_output_base_dir(script_dir):
    """設定ファイルから出力先を読み込む。なければ現在ログイン中のユーザーのデスクトップを使用"""
    config_path = os.path.join(script_dir, "config.txt")
    
    # ログインユーザーのホームディレクトリから動的にデスクトップを取得（マルチユーザー完全対応）
    desktop_dir = os.path.join(os.path.expanduser("~"), "Desktop")
    default_base_dir = os.path.join(desktop_dir, "JPEG")
    
    # config.txtがあればそこから読み込む
    if os.path.exists(config_path):
        try:
            with open(config_path, "r", encoding="utf-8") as f:
                line = f.readline().strip()
                if line:
                    return line
        except Exception:
            pass
            
    # なければ設定ファイルを自動作成しておく
    try:
        with open(config_path, "w", encoding="utf-8") as f:
            f.write(default_base_dir)
    except Exception:
        pass
        
    return default_base_dir

def main():
    print("==========================================================")
    print("  🏥 胃がん検診 DICOM → JPEG 単発変換 & ベリファイ 🚀 [Ver 1.5]")
    print("==========================================================")
    
    # スクリプト自体の場所を取得
    script_dir = os.path.dirname(os.path.abspath(__file__))
    
    # 引数のチェック
    if len(sys.argv) < 3:
        print("[エラー] 引数が足りません。")
        print(f"受け取った引数一覧: {sys.argv}")
        input("[Enter] キーを押すと終了します ...")
        sys.exit(1)
        
    target_folder = sys.argv[1]
    sub_folder = sys.argv[2]
    
    # 出力先ベースディレクトリを取得（設定ファイル or ユーザーデスクトップ自動取得）
    base_output_dir = get_output_base_dir(script_dir)
    output_dir = os.path.join(base_output_dir, sub_folder)
    
    print(f"【DEBUG】入力DICOMフォルダ: [{target_folder}]")
    print(f"【DEBUG】出力先ベース: [{base_output_dir}]")
    print(f"【DEBUG】出力先JPEGフォルダ: [{output_dir}]")
    
    if not os.path.exists(target_folder):
        print(f"\n[エラー] 指定されたDICOMフォルダが存在しません。")
        print(f"👉 探しに行ったパス: [{target_folder}]")
        input("[Enter] キーを押すと終了します ...")
        sys.exit(1)
        
    if not os.path.exists(output_dir):
        try:
            os.makedirs(output_dir, exist_ok=True)
            print(f"出力先フォルダを新規作成しました: {output_dir}")
        except Exception as e:
            print(f"\n[エラー] 出力先フォルダの作成に失敗しました: {e}")
            input("[Enter] キーを押すと終了します ...")
            sys.exit(1)
            
    print(f"\nDICOMファイルの検索と変換を開始します...")
    
    success_count = 0
    
    for root, dirs, files in os.walk(target_folder):
        for file in files:
            dcm_path = os.path.join(root, file)
            try:
                ds = pydicom.dcmread(dcm_path, force=True)
                if not hasattr(ds, 'pixel_array'):
                    continue
                
                pixel_array = ds.pixel_array
                image = Image.fromarray(pixel_array)
                
                if image.mode != 'RGB':
                    image = image.convert("L")
                
                base_name = os.path.splitext(file)[0]
                jpeg_filename = f"{base_name}.jpg"
                jpeg_path = os.path.join(output_dir, jpeg_filename)
                
                image.save(jpeg_path, "JPEG")
                print(f"  [変換成功] {file} -> {jpeg_filename}")
                success_count += 1
                
            except Exception as e:
                pass

    print(f"\n------------------------------------------")
    print(f" 変換完了！ 成功: {success_count} ファイル")
    print(f" 保存先: {output_dir}")
    print(f"------------------------------------------")
    
    input("\n[Enter] キーを押すと終了します ...")

if __name__ == '__main__':
    main()