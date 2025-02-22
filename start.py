import os

# Define the project structure
project_structure = {
    "config": ["config.php"],
    "src": ["ZAPIHandler.php", "ChatwootHandler.php", "Logger.php"],
    "public": ["webhook.php"],
    "logs": ["app.log"],
    "": ["composer.json"]
}

# Create directories and files
for folder, files in project_structure.items():
    # Create directory if it doesn't exist
    if folder and not os.path.exists(folder):
        os.makedirs(folder)

    # Create files in the directory
    for file in files:
        file_path = os.path.join(folder, file)
        with open(file_path, 'w') as f:
            pass  # Create an empty file

print("Estrutura do projeto criada com sucesso!")
