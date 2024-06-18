document.addEventListener('DOMContentLoaded', () => {
   const connectButton = document.getElementById('connectButton');
   const exportButton = document.getElementById('exportButton');
   const databaseListContainer = document.getElementById('databaseListContainer');
   const databaseSelect = document.getElementById('dbname');
   const spinner = document.getElementById('spinner');

   connectButton.addEventListener('click', () => {
      const formData = new FormData(document.getElementById('exportForm'));

      fetch('process_db_export.php', {
         method: 'POST',
         body: formData,
      })
         .then(response => response.json())
         .then(data => {
            console.log('Connect Response:', data); // Log the response
            if (data.status === 'success') {
               Swal.fire({
                  title: 'Success!',
                  text: data.message,
                  icon: 'success',
               });
               // Populate database select options
               populateDatabaseOptions(data.databases);
               databaseListContainer.style.display = 'block';
               exportButton.disabled = false;
            } else if (data.status === 'error') {
               Swal.fire({
                  title: 'Error!',
                  text: data.message,
                  icon: 'error',
               });
               exportButton.disabled = true;
            }
         })
         .catch(error => {
            Swal.fire({
               title: 'Error!',
               text: 'An error occurred while connecting to the database.',
               icon: 'error',
            });
            console.error('Error:', error);
         });
   });

   exportButton.addEventListener('click', () => {
      const formData = new FormData(document.getElementById('exportForm'));
      const exportOption = document.querySelector('input[name="exportOption"]:checked').value;
      formData.append('export', 'true');
      formData.append('dbname', databaseSelect.value);
      formData.append('exportOption', exportOption); // Include the selected export option

      spinner.style.display = 'inline-block'; // Show the spinner

      fetch('process_db_export.php', {
         method: 'POST',
         body: formData,
      })
         .then(response => {
            if (!response.ok) {
               throw new Error('Network response was not ok');
            }
            return response.blob();
         })
         .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            if (exportOption === 'single') {
               a.href = url;
               a.download = databaseSelect.value + '.sql'; // Use the selected database name
            } else if (exportOption === 'zip') {
               a.href = url;
               a.download = databaseSelect.value + '_tables.zip'; // Use the selected database name for zip file
            }
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            Swal.fire({
               title: 'Success!',
               text: 'Database backup created and downloaded successfully.',
               icon: 'success',
            });
            spinner.style.display = 'none'; // Hide the spinner
         })
         .catch(error => {
            Swal.fire({
               title: 'Error!',
               text: 'An error occurred while exporting the database.',
               icon: 'error',
            });
            spinner.style.display = 'none'; // Hide the spinner
            console.error('Error:', error);
         });
   });

   function populateDatabaseOptions(databases) {
      databaseSelect.innerHTML = '';
      databases.forEach(database => {
         const option = document.createElement('option');
         option.value = database;
         option.textContent = database;
         databaseSelect.appendChild(option);
      });
   }
});

function password_show_hide() {
   var x = document.getElementById('password');
   var show_eye = document.getElementById('show_eye');
   var hide_eye = document.getElementById('hide_eye');
   hide_eye.classList.remove('d-none');
   if (x.type === 'password') {
      x.type = 'text';
      show_eye.style.display = 'none';
      hide_eye.style.display = 'block';
   } else {
      x.type = 'password';
      show_eye.style.display = 'block';
      hide_eye.style.display = 'none';
   }
}
