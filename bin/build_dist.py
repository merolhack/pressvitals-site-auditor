import os
import subprocess
import shutil

src_dir = '/home/merolhack/fl/pressvitals-site-auditor'
tmp_dir = '/tmp/pressvitals-site-auditor'
tmp_sub_dir = os.path.join(tmp_dir, 'pressvitals-site-auditor')

if os.path.exists(tmp_dir):
    shutil.rmtree(tmp_dir)
os.makedirs(tmp_sub_dir, exist_ok=True)

distignore = os.path.join(src_dir, '.distignore')
subprocess.run(['rsync', '-a', f'--exclude-from={distignore}', f'{src_dir}/', f'{tmp_sub_dir}/'], check=True)
shutil.make_archive(os.path.join(src_dir, 'pressvitals-site-auditor'), 'zip', tmp_dir)
shutil.rmtree(tmp_dir, ignore_errors=True)
zip_path = os.path.join(src_dir, 'pressvitals-site-auditor.zip')
import zipfile
with zipfile.ZipFile(zip_path, 'r') as z:
    names = z.namelist()
    leaks = [n for n in names if any(bad in n for bad in ['/wiki', '/tests', '/bin', '.git', 'LLM_WIKI'])]
    print(f"ZIP files total: {len(names)}, Leaks found: {leaks}")
print(f"Distribution ZIP successfully created: {zip_path} ({os.path.getsize(zip_path)} bytes)")
