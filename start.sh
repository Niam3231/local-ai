#!/usr/bin/env bash
set -euo pipefail

echo "Hello! This script will install Ollama, download AI models, install PHP, and start a local server on localhost:3231."
echo "You’ll need about 5.2GB of space, and the downloads will consume similar internet data."
echo "If you’ve already installed before, type 'y' to just start the server."
read -r answer

if [[ "$answer" =~ ^[Yy]$ ]]; then
  clear
  echo "Starting server..."
  echo "Go to http://localhost:3231 to talk to the AI."
  sleep 0.5
  cd AI || { echo "Error: AI folder not found."; exit 1; }
  php -S 127.0.0.1:3231
  exit 0
fi

clear
echo "Installing Ollama (1/7)"
sleep 1
local_ver=$(command -v ollama >/dev/null 2>&1 && ollama --version | awk '{print $NF}' || echo none)
latest_ver=$(curl -fsSL https://api.github.com/repos/ollama/ollama/releases/latest | grep -Po '"tag_name": *"v?\K[^"]+')
if [ "$local_ver" = "none" ]; then
  echo "Installing Ollama..."
  curl -fsSL https://ollama.com/install.sh | sh
elif [ "$local_ver" != "$latest_ver" ]; then
  echo "Updating Ollama ($local_ver → $latest_ver)..."
  curl -fsSL https://ollama.com/install.sh | sh
else
  echo "Ollama is up to date ($local_ver)"
fi

clear
echo "Model selection (2/7)"
sleep 1

# ---- MODEL SELECTION SECTION ----
MODEL_CATALOG=(
'gemma3:270m|Gemma 3 270m|Very fast but not very smart.|292 MB'
'qwen3:0.6b|Qwen 3 0.6b|Reasonable and quick.|523 MB'
'deepseek-r1:1.5b|DeepSeek R1 1.5b|Smarter, a bit slower.|1.1 GB'
'gemma3:4b|Gemma 3 4b|Balanced and capable.|3.3 GB'
'deepseek-r1:7b|DeepSeek R1 7b|Good reasoning, for mid systems.|4.7 GB'
'deepseek-r1:14b|DeepSeek R1 14b|High-end only.|9.0 GB'
'deepseek-r1:32b|DeepSeek R1 32b|Needs serious hardware.|20 GB'
)

echo "Available models:"
i=1
for entry in "${MODEL_CATALOG[@]}"; do
  IFS='|' read -r id name descr size <<< "$entry"
  printf "%2d) %s\n    ID: %s\n    Size: %s\n    %s\n\n" "$i" "$name" "$id" "$size" "$descr"
  ((i++))
done

read -r -p "Enter the numbers of the models you want (e.g., 1 3 4 or 'all'): " selection
selection=${selection:-all}
echo

selected_ids=()

if [[ "$selection" == "all" ]]; then
  for entry in "${MODEL_CATALOG[@]}"; do
    IFS='|' read -r id _ _ _ <<< "$entry"
    selected_ids+=("$id")
  done
else
  for num in $selection; do
    if [[ "$num" =~ ^[0-9]+$ ]] && (( num >= 1 && num <= ${#MODEL_CATALOG[@]} )); then
      IFS='|' read -r id _ _ _ <<< "${MODEL_CATALOG[$((num-1))]}"
      selected_ids+=("$id")
    else
      echo "Warning: invalid selection '$num', skipping."
    fi
  done
fi

if [ ${#selected_ids[@]} -eq 0 ]; then
  echo "No models selected. Exiting."
  exit 1
fi

clear
echo "Downloading selected models (3/7)"
sleep 1
for model in "${selected_ids[@]}"; do
  echo "Pulling model: $model"
  ollama pull "$model"
  echo
done

# ---- CONTINUE NORMAL INSTALLATION ----
clear
echo "Installing PHP (4/7)"
sleep 1
sudo apt update
sudo apt install php php-curl -y

clear
echo "Setting up local AI server (5/7)"
sleep 1
mkdir -p AI
cd AI
curl -fsSL -o index.php https://raw.githubusercontent.com/Niam3231/local-ai/main/index.php

# Generate models.json for PHP front-end
echo "[" > models.json
for id in "${selected_ids[@]}"; do
  echo "  {\"id\": \"$id\"}," >> models.json
done
sed -i '$ s/,$//' models.json
echo "]" >> models.json

clear
echo "Starting PHP server (6/7)"
sleep 0.5
echo "Go to http://localhost:3231 to talk to the AI."
php -S 127.0.0.1:3231
