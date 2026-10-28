"""Read-only checks before starting the isolated Compose project."""

import os
import shutil
import socket
from pathlib import Path


def main() -> None:
    settings = {}
    env_path = Path(__file__).resolve().parents[1] / ".env"
    if env_path.exists():
        for line in env_path.read_text().splitlines():
            if line.strip() and not line.lstrip().startswith("#") and "=" in line:
                key, value = line.split("=", 1)
                settings[key.strip()] = value.strip().strip("\"'")
    for key, default in (("BACKEND_PORT", "18000"), ("FRONTEND_PORT", "15173")):
        port = int(os.environ.get(key, settings.get(key, default)))
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", port))
        print(f"Porta local {port}: livre")
    disk = shutil.disk_usage(env_path.parent)
    print(f"Disco livre: {disk.free / 1024**3:.1f} GiB")
    memory = dict(line.split(":", 1) for line in Path("/proc/meminfo").read_text().splitlines())
    available = int(memory["MemAvailable"].split()[0]) / 1024**2
    print(f"Memória disponível: {available:.1f} GiB")
    if disk.free < 4 * 1024**3 or available < 1.5:
        raise SystemExit(
            "Recursos insuficientes para build/inicialização; revise antes de continuar."
        )
    print("Verificações locais concluídas. Confira docker compose ls e os serviços existentes.")


if __name__ == "__main__":
    main()
